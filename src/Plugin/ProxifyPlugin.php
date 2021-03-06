<?php

namespace Proxy\Plugin;

use Proxy\Plugin\AbstractPlugin;
use Proxy\Event\ProxyEvent;

class ProxifyPlugin extends AbstractPlugin {

	// what about urls like these? ../fonts/base/TheSans_LT_TT4i.svg
	function css_url($matches){
	
		$url = trim($matches[1]);
		
		if(stripos($url, 'data:') === 0){
			return $matches[0];
		}
		
		return ': url(\''.proxify_url($url).'\')';
	}

	function html_href($matches){
		
		$url = $matches[2];
		
		if(stripos($url, "javascript:") === 0){
			return $matches[0];
		}
	
		return 'href='.$matches[1].proxify_url($url).$matches[3];
	}

	function html_src($matches){

		if(stripos(trim($matches[1]), 'data:') === 0){
			return $matches[0];
		}
		
		return 'src="'.proxify_url($matches[1]).'"';
	}

	function html_action($matches){

		$new_action = proxify_url($matches[1]);
		$result = str_replace($matches[1], $new_action, $matches[0]);
		
		// change form method to POST!!!
		$result = str_replace("<form", '<form method="POST"', $result);
		return $result;
	}

	// request response headers content_type
	public function onCompleted(ProxyEvent $event){
	
		$response = $event['response'];
		$str = $response->getContent();

		// let's remove all frames??
		$str = preg_replace('@<iframe[^>]+>.*?<\\/iframe>@is', '', $str);
		
		// css
		$str = preg_replace_callback('@:\s*url\s*\((?:\'|"|)(.*?)(?:\'|"|)\)@im', array($this, 'css_url'), $str);
		
		// html
		$str = preg_replace_callback('@href\s*=\s*(["|\'])([^"\']+)(["|\'])@im', array($this, 'html_href'), $str);
		$str = preg_replace_callback('@src=["|\']([^"\']+)["|\']@i', array($this, 'html_src'), $str);
		$str = preg_replace_callback('@<form[^>]*action=["|\'](.+?)["|\'][^>]*>@i', array($this, 'html_action'), $str);
		
		
		$response->setContent($str);
	}



}

?>