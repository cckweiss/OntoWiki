<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2011, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * Controller for the OntoWiki files extension
 *
 * @category OntoWiki
 * @package  OntoWiki_extensions_files
 * @author   Christoph Rieß <c.riess.dev@googlemail.com>
 * @author   Norman Heino <norman.heino@gmail.com>
 * @author   {@link http://sebastian.tramp.name Sebastian Tramp}
 */
class FilesController extends OntoWiki_Controller_Component
{
    protected $_configModel;

    /**
     * Default action. Forwards to get action.
     */
    public function __call($action, $params)
    {
        $this->_forward('get', 'files');
    }

    /**
     * deletes a file resource from the disk as well as from the config model
     * but NOT from the user-model
     */
    private function _deleteFile($fileResource)
    {
        $store = $this->_owApp->erfurt->getStore();

        // remove file from file system (silently)
        $pathHashed = $this->getFullPath($fileResource);
        if (is_readable($pathHashed)) {
            unlink($pathHashed);
        }

        // remove all statements from sysconfig
        $store->deleteMatchingStatements(
            (string) $this->_getConfigModelUri(),
            $fileResource,
            null,
            null
        );
    }

    /**
     * action to delete a file resource either via post (multiple) or via get
     * (setResource parameter
     */
    public function deleteAction()
    {
        // delete file resources via Post array
        if ($this->_request->isPost()) {
            foreach ($this->_request->getPost('selectedFiles') as $fileUri) {
                $fileUri = rawurldecode($fileUri);
                $this->_deleteFile($fileUri);
            }

            $url = new OntoWiki_Url(array('controller' => 'files', 'action' => 'manage'), array());
            $this->_redirect((string) $url);
        } else if (isset($this->_request->setResource)) {
            // delete a resource via get setResource parameter
            $fileUri = rawurldecode($this->_request->setResource);
            $this->_deleteFile($this->_request->setResource);
            $this->_owApp->appendMessage(
                new OntoWiki_Message('File attachment deleted', OntoWiki_Message::SUCCESS)
            );
            $resourceUri = new OntoWiki_Url(array('route' => 'properties'), array('r'));
            $resourceUri->setParam('r', $this->_request->setResource, true);
            $this->_redirect((string) $resourceUri);
        } else {
            // action just requested without anything
            $this->_forward('manage', 'files');
        }
    }

    /**
     * get / download a file resource
     */
    public function getAction()
    {
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        // TODO: check acl
        if (isset($this->_request->setResource)) {
            $fileUri = $this->_request->setResource;
        } else {
            $fileUri = $this->_config->urlBase . ltrim($this->_request->getPathInfo(), '/');
        }
        $mimeProperty = $this->_privateConfig->mime->property;
        $store        = $this->_owApp->erfurt->getStore();

        $query = new Erfurt_Sparql_SimpleQuery();
        $query->setProloguePart('SELECT DISTINCT ?mime_type')
              ->addFrom((string) $this->_getConfigModelUri())
              ->setWherePart('WHERE {<' . $fileUri . '> <' . $mimeProperty . '> ?mime_type. }');

        if ($result = $store->sparqlQuery($query, array('use_ac' => false))) {
            $mimeType = $result[0]['mime_type'];
        } else {
            // we set the default download file type to 
            // application/octet-stream
            $mimeType = 'application/octet-stream';
        }

        // TODO: generate a proper file name here
        $response = $this->getResponse();
        $response->setRawHeader('Content-Type:' . $mimeType);
        $pathHashed = $this->getFullPath($fileUri);
        if (is_readable($pathHashed)) {
            $response->setBody(file_get_contents($pathHashed));
        }
    }

    /**
     * manage file resources (main GUI)
     */
    public function manageAction()
    {
        $mimeProperty = $this->_privateConfig->mime->property;
        $fileClass    = $this->_privateConfig->class;
        $fileModel    = $this->_privateConfig->model;
        $store        = $this->_owApp->erfurt->getStore();
		$selectedModel= (string) $this->_owApp->selectedModel;
		$udfrBaseUri  = $this->_privateConfig->udfr->baseUri;
        $query = new Erfurt_Sparql_SimpleQuery();
        $query->setProloguePart('SELECT DISTINCT ?mime_type ?uri')
            ->addFrom((string) $this->_getConfigModelUri())
            ->setWherePart(
                'WHERE
                {
                    ?uri a <' . $fileClass . '>.
                    ?uri <' . $fileModel . '> <' . (string) $this->_owApp->selectedModel . '>.
                    ?uri <' . $mimeProperty . '> ?mime_type.
                }'
            )
            ->setOrderClause('?uri')
            ->setLimit(10); // TODO: paging
		
        if ($result = $store->sparqlQuery($query, array('use_ac' => false))) {
            $files = array();
            foreach ($result as $row) {
                if (is_readable($this->getFullPath($row['uri']))) {
                    array_push($files, $row);
                }
            }
            $this->view->files = $files;
        } else {
            $this->view->files = array();
			if (empty ($files)) {
				if ($selectedModel == $udfrBaseUri) {
					$this->_owApp->appendMessage(
                            new OntoWiki_Message('There are no uploaded files.
							', OntoWiki_Message::INFO)
                        );
				} else {
				$this->_owApp->appendMessage(
                            new OntoWiki_Message('All uploaded files are saved in the UDFR model.
							Please select the UDFR model to see files associated with the UDFR registry.
							', OntoWiki_Message::INFO)
                        );
				}
			}
		}

        $this->view->placeholder('main.window.title')->set($this->_owApp->translate->_('File Manager'));
        OntoWiki_Navigation::disableNavigation();

        $toolbar = $this->_owApp->toolbar;

        $filePath = _OWROOT
                  . rtrim($this->_privateConfig->path, '/')
                  . DIRECTORY_SEPARATOR;

        $url = new OntoWiki_Url(array('controller' => 'files', 'action' => 'upload'), array());

        if (is_writable($filePath)) {

            $toolbar->appendButton(
                OntoWiki_Toolbar::DELETE,
                array('name' => 'Delete Files', 'class' => 'submit actionid', 'id' => 'filemanagement-delete')
            );

            $toolbar->appendButton(
                OntoWiki_Toolbar::ADD,
                array('name' => 'Upload File', 'class' => 'upload-file', 'url' => (string) $url)
            );

            $this->view->placeholder('main.window.toolbar')->set($toolbar);
        } else {
            $msgString = sprintf(
                $this->_owApp->translate->_('Directory "%s" is not writeable. To upload files set it writable.'),
                rtrim($this->_privateConfig->path, '/') . DIRECTORY_SEPARATOR
            );
            $this->_owApp->appendMessage(
                new OntoWiki_Message($msgString, OntoWiki_Message::INFO)
            );
        }

        if (!defined('ONTOWIKI_REWRITE')) {
            $this->_owApp->appendMessage(
                new OntoWiki_Message('Rewrite mode is off. File URIs may not be accessible.', OntoWiki_Message::WARNING)
            );
            return;
        }

        $url->action = 'delete';
        $this->view->formActionUrl = (string) $url;
        $this->view->formMethod    = 'post';
        $this->view->formClass     = 'simple-input input-justify-left';
        $this->view->formName      = 'filemanagement-delete';
    }

    /**
     * upload a file resource via POST or get upload GUI
     */
    public function uploadAction()
    {
        // default file URI
        $defaultUri = $this->_config->urlBase . 'files/';

        // store for sparql queries
        $store        = $this->_owApp->erfurt->getStore();

        // DMS NS var
        $dmsNs = $this->_privateConfig->DMS_NS;

        // check if DMS needs to be imported
        if ($store->isModelAvailable($dmsNs) && $this->_privateConfig->import_DMS) {
            $this->_checkDMS();
        }
		$udfrBaseUri  = $this->_privateConfig->udfr->baseUri;
		$selectedModel= (string) $this->_owApp->selectedModel;
		if ($selectedModel != $udfrBaseUri) {
				$this->_owApp->appendMessage(
							new OntoWiki_Message('All uploaded files will be saved in the UDFR model.
							Please select the UDFR model to see a list of your uploaded files.
							', OntoWiki_Message::WARNING)
						);
			}


        $url = new OntoWiki_Url(array('controller' => 'files', 'action' => 'upload'), array());

        // check for POST'ed data
        if ($this->_request->isPost()) {
			$currentNoid = $this->noidId();
			if (!empty ($_POST['file_name'])) { 
			if (preg_match("/u1r/i", $currentNoid)) {
			 
            if ($_FILES['upload']['error'] == UPLOAD_ERR_OK) {
                // upload ok, move file
                $fileUri  = $this->_request->getPost('file_uri');
                $fileName = $_FILES['upload']['name'];
                $tmpName  = $_FILES['upload']['tmp_name'];
                $mimeType = $_FILES['upload']['type'];
 
                // check for unchanged uri
                if ($fileUri == $defaultUri) {
                    $fileUri = $defaultUri
                        . 'file'
                        . (count(scandir(_OWROOT . $this->_privateConfig->path)) - 2);
                }

                // build path
				$suffixType = substr($fileName, strrpos($fileName, '.'));
                $pathHashed = $this->getFullPath($fileUri);

                // move file
                if (move_uploaded_file($tmpName, $pathHashed)) {
                    $mimeProperty 		= $this->_privateConfig->mime->property;
                    $fileClass    		= $this->_privateConfig->class;
                    $fileModel    		= $this->_privateConfig->model;
					$udfrBaseUri  		= $this->_privateConfig->udfr->baseUri;
					$udfrsFile 	  		= $this->_privateConfig->udfrs->File;
					$udfrsFileLocation 	= $this->_privateConfig->udfrs->fileLocation;
					$dctDescription 	= $this->_privateConfig->dct->description;
					
                    // use super class as default
                    $fileClassLocal = 'http://xmlns.com/foaf/0.1/Document';
					
                    // use mediaType-ontologie if available
                    if ($store->isModelAvailable($dmsNs)) {
                        $allTypes = $store->sparqlQuery(
                            Erfurt_Sparql_SimpleQuery::initWithString(
                                'SELECT * FROM <' . $dmsNs . '>
                                WHERE {
                                    ?type a <' . EF_OWL_CLASS . '> .
                                    OPTIONAL { ?type <' . $dmsNs . 'mimeHint> ?mimeHint . }
                                    OPTIONAL { ?type <' . $dmsNs . 'suffixHint> ?suffixHint . }
                                } ORDER BY ?type'
                            )
                        );

                        $mimeHintArray = array();
                        $suffixHintArray = array();

                        // check for better suited class
                        foreach ($allTypes as $singleType) {
                            if (!empty($singleType['mimeHint'])) {
                                $mimeHintArray[$singleType['mimeHint']]     = $singleType['type'];
                            }
                            if (!empty($singleType['suffixHint'])) {
                                $suffixHintArray[$singleType['suffixHint']]   = $singleType['type'];
                            }
                        }

                        $suffixType = substr($fileName, strrpos($fileName, '.'));
                        if (array_key_exists($suffixType, $suffixHintArray)) {
                            $fileClassLocal = $suffixHintArray[$suffixType];
                        }

                        if (array_key_exists($mimeType, $mimeHintArray)) {
                            $fileClassLocal = $mimeHintArray[$mimeType];
                        }
                    }

                    // add file resource as instance in local model
                    $store->addStatement(
                        $udfrBaseUri,
                        $fileUri,
                        EF_RDF_TYPE,
                        array('value' => $fileClassLocal, 'type' => 'uri')
                    );
                    // add file resource as instance in system model
                    $store->addStatement(
                        (string) $this->_getConfigModelUri(),
                        $fileUri,
                        EF_RDF_TYPE,
                        array('value' => $fileClass, 'type' => 'uri'),
                        false
                    );
                    // add file resource mime type
                    $store->addStatement(
                        (string) $this->_getConfigModelUri(),
                        $fileUri,
                        $mimeProperty,
                        array('value' => $mimeType, 'type' => 'literal'),
                        false
                    );
                    // add file resource model
                    $store->addStatement(
                        (string) $this->_getConfigModelUri(),
                        $fileUri,
                        $fileModel,
                        array('value' => $udfrBaseUri, 'type' => 'uri'),
                        false
                    );
					//add new instance of onto/File
					$store->addStatement(
                        $udfrBaseUri,
                        $udfrBaseUri.$currentNoid,
                        EF_RDF_TYPE,
                        array('value' => $udfrsFile, 'type' => 'uri'),
                        false
                    );
					$store->addStatement(
                        $udfrBaseUri,
                        $udfrBaseUri.$currentNoid,
                        EF_RDFS_LABEL,
                        array('value' => $_POST['file_name'], 'type' => 'literal'),
                        false
                    );
					if (!empty ($_POST['file_description'])) {
						$store->addStatement(
							$udfrBaseUri,
							$udfrBaseUri.$currentNoid,
							$dctDescription,
							array('value' => $_POST['file_description'], 'type' => 'literal'),
							false
						);
					}
					$store->addStatement(
						$udfrBaseUri,
						$udfrBaseUri.$currentNoid,
						$udfrsFileLocation,
						array('value' => $fileUri, 'type' => 'literal'),
						false
					);
                    if (isset($this->_request->setResource)) {
                        $this->_owApp->appendMessage(
                            new OntoWiki_Message('File attachment added', OntoWiki_Message::SUCCESS)
                        );
                        $resourceUri = new OntoWiki_Url(array('route' => 'properties'), array('r'));
                        $resourceUri->setParam('r', $this->_request->setResource, true);
                        $this->_redirect((string) $resourceUri);
                    } else {
                        $url->action = 'manage';
                        $this->_redirect((string) $url);
                    }
                
				}
            } else {
                $this->_owApp->appendMessage(
                    new OntoWiki_Message('Error during file upload.', OntoWiki_Message::ERROR)
                );
            }
			} else {
					$this->_owApp->appendMessage(
                    new OntoWiki_Message('Error occured when get noid identifier. Please try again or check the noid server configuration.', OntoWiki_Message::ERROR)
                );
			}
			} else {
                $this->_owApp->appendMessage(
                    new OntoWiki_Message('Please enter File Label.', OntoWiki_Message::ERROR)
                );
            }
        }

        $this->view->placeholder('main.window.title')->set($this->_owApp->translate->_('Upload File'));
        OntoWiki_Navigation::disableNavigation();

        $toolbar = $this->_owApp->toolbar;
        $url->action = 'manage';
        $toolbar->appendButton(
            OntoWiki_Toolbar::SUBMIT, array('name' => 'Upload File')
        );
        $toolbar->appendButton(
            OntoWiki_Toolbar::EDIT, array('name' => 'File Manager', 'class' => '', 'url' => (string) $url)
        );

        $this->view->defaultUri = $defaultUri;
        $this->view->placeholder('main.window.toolbar')->set($toolbar);

        $url->action = 'upload';
        $this->view->formActionUrl = (string) $url;
        $this->view->formMethod    = 'post';
        $this->view->formClass     = 'simple-input input-justify-left';
        $this->view->formName      = 'fileupload';
        $this->view->formEncoding  = 'multipart/form-data';
        if (isset($this->_request->setResource)) {
            // forward URI to form so we can redirect later
            $this->view->setResource  = $this->_request->setResource;
        }

        if (!is_writable($this->_privateConfig->path)) {
            $this->_owApp->appendMessage(
                new OntoWiki_Message('Uploads folder is not writable.', OntoWiki_Message::WARNING)
            );
            return;
        }

        // FIX: http://www.webmasterworld.com/macintosh_webmaster/3300569.htm
        header('Connection: close');
    }

    /**
     * method to check import of DMS Schema in current model
     */
    private function _checkDMS()
    {

        $store        = $this->_owApp->erfurt->getStore();

        // checking if model is imported
        $allImports = $this->_owApp->selectedModel->sparqlQuery(
            Erfurt_Sparql_SimpleQuery::initWithString(
                'SELECT *
                WHERE {
                    <' . (string) $this->_owApp->selectedModel . '> <' . EF_OWL_IMPORTS . '> ?import .
                }'
            )
        );

        // import if missing
        if (!in_array(array('import' => $this->_privateConfig->DMS_NS), $allImports)) {
            $this->_owApp->selectedModel->addStatement(
                (string) $this->_owApp->selectedModel,
                EF_OWL_IMPORTS,
                array('value' => $this->_privateConfig->DMS_NS, 'type' => 'uri'),
                false
            );
        } else {
            // do nothing
        }

    }

    protected function _getConfigModelUri()
    {
        if (null === $this->_configModel) {
            $this->_configModel = Erfurt_App::getInstance()->getConfig()->sysont->modelUri;
        }

        return $this->_configModel;
    }

    /**
     * return the file path incl. incl. filename for a given resource
     */
    public static function getFullPath($fileResource)
    {
        $extensionManager = OntoWiki::getInstance()->extensionManager;
        $privateConfig    = $extensionManager->getPrivateConfig('files');
        $path             = $privateConfig->path;

        return _OWROOT . $path . DIRECTORY_SEPARATOR . md5($fileResource);
    }

    /**
     * Returns the queried mime type (or application/octet-stream) for a given 
     * file resource
     */
    public static function getMimeType($fileResource)
    {
        $owApp            = OntoWiki::getInstance();
        $store            = $owApp->erfurt->getStore();
        $extensionManager = $owApp->extensionManager;
        $configModel      = $owApp->erfurt->getConfig()->sysont->modelUri;
        $privateConfig    = $extensionManager->getPrivateConfig('files');
        $mimeProperty     = $privateConfig->mime->property;

        $query = new Erfurt_Sparql_SimpleQuery();
        $query->setProloguePart('SELECT DISTINCT ?mime_type')
            ->addFrom($configModel)
            ->setWherePart('WHERE {<' . $fileResource . '> <' . $mimeProperty . '> ?mime_type. }');

        if ($result = $store->sparqlQuery($query, array('use_ac' => false))) {
            $mimeType = $result[0]['mime_type'];
        } else {
            // we set the default download file type to 
            // application/octet-stream
            $mimeType = 'application/octet-stream';
        }
        return $mimeType;
    }
	
	public function noidId()
	{
		$fp = fsockopen($this->_owApp->config->noidServer->hostName, $this->_owApp->config->noidServer->port, $errno, $errstr, 30);

			if (!$fp) {
				echo "$errstr ($errno)<br />\n";
			} else {
					$out = "GET http://" . $this->_owApp->config->noidServer->hostName . $this->_owApp->config->noidServer->u1r . " HTTP/1.0\r\n";
					$out .= "Host: ".$this->_owApp->config->noidServer->hostName."\r\n";
					$out .= "Connection: Close\r\n\r\n";
					fwrite($fp, $out);
					
					while (!feof($fp)) {
						$noid = fgets($fp, 128); 				
					}
					fclose($fp);
			}
			$noid = trim($noid);
			return $noid;
	}
}

