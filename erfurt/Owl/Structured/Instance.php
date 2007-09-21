<?php

class Erfurt_Owl_Structured_Instance 
{
	private $uri;

	public function __construct($uri) {
		$this->uri = $uri;
	}

	public function getURI(){
		return $this->uri;
	}
	
	public function toManchesterSyntaxString(){
		return $this->uri;
	}

}
?>