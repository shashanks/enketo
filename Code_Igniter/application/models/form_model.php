<?php

class Form_model extends CI_Model {
	
	// TURN INTO CONSTANTS / CONFIG ITEMS?
	private $file_path_to_jr2HTML5_XSL;
	private $file_path_to_jr2Data_XSL;//add later

    function __construct()
    {
        parent::__construct();
        $this->load->helper(array('http'));
        //$this->load->library('curl'); //Remove if not used
        $this->file_path_to_jr2HTML5_XSL = APPPATH.'libraries/jr2html5_php5.xsl'; //_php5 indicates that it has been 'fixed' for the php 5 processor
        //$this->load->database();
        $this->file_path_to_jr2Data_XSL = APPPATH.'libraries/jr2xmldata.xsl';
        log_message('debug', 'Form Model loaded');
    }
    
    function transform($xml_url, $feedback = FALSE)
    {
    	$xml = $this->_load_xml($xml_url);    	
    	$xsl_form = $this->_load_xml($this->file_path_to_jr2HTML5_XSL);
    	$xsl_data = $this->_load_xml($this->file_path_to_jr2Data_XSL);

        //get manifest if exists
        if ($xml['type'] == 'url')
        {
            $manifest_url = $this->_get_manifest_url($xml_url);
            log_message('debug', 'received: '.$manifest_url);
            if ($manifest_url !== FALSE)
            {
                $manifest_o = $this->_load_xml($manifest_url);
                $manifest_sxe = simplexml_import_dom($manifest_o['doc']);
                log_message('debug', $manifest_sxe->asXML()); 
            }
        }
        else 
        {
            $manifest_sxe = FALSE;
        }

		$result = new SimpleXMLElement('<root></root>');

		//ADD simplified transform for if $feedback = FALSE 
		if ($xml['doc'] && $xsl_form['doc'] && $xsl_data['doc'])
		{
			$odk_result = $this->_odk_validate($xml['doc']);
			if ($odk_result['pass'] === TRUE)
			{
				//perform transformation to HTML5 form and get xslt messages
				$result = $this->_xslt_transform($xml['doc'], $xsl_form['doc']);
				//perform transformation to get data
				$data = $this->_xslt_transform($xml['doc'], $xsl_data['doc']);
				
				$this->_fix_lang($result);
                
                //fix media urls
                if (isset($manifest_sxe))
                {
                    $this->_fix_media_urls($manifest_sxe, $result);
                }

				//easiest way to merge data and result
				$result = simplexml_load_string('<root>'.$result->form->asXML().$data->instance->asXML().$result->xsltmessages->asXML().'</root>');
				
				//$this->_html5_validate($result);
				//log_message('debug', 'data: '.$data->asXML());				
				//log_message('debug', 'result after appending: '.$result->asXML());
			}
			$result = $this->_add_errors($odk_result['errors'], 'jrvalidationmessages', $result);		
		}	
		$result = $this->_add_errors($xml['errors'], 'xmlerrors', $result);
		$result = $this->_add_errors($xsl_form['errors'], 'xslformerrors', $result);
		$result = $this->_add_errors($xsl_data['errors'], 'xsldataerrors', $result);

    	return $result;
    }
   	

    //private function to guess the manifest url for ODK Aggregate-hosted forms (ADD: Formhub)
    private function _get_manifest_url($xml_url)
    {
        //check if there is a manifest using ODK Aggregate's url convention 
        if (strrpos($xml_url, '/formXml?') > 0)
        {
            $manifest_url = str_replace('/formXml?', '/xformsManifest?', $xml_url);
            log_message('debug', 'guessed manifest url: '.$manifest_url);
            //$manifest = new DomDocument;
            //$manifest->load($manifest_url);
        }
        //if (!isset($manifest) || !$manifest)
        else
        {
            $manifest_url = FALSE;
        }
        return $manifest_url;
        //else 
        //{
        //    $manifest = simplexml_import_dom($manifest);
            //log_message('debug', 'manifest: '.$manifest->asXML());
        //}
    }

    //loads xml resource into DOMDocument object 
    private function _load_xml($resource)
    {
		log_message('debug', 'loading XML/XSL file with path:'.$resource);    
    	if (file_exists($resource))
    	{
    		$type = 'file';
    		log_message('debug', 'file exists!');
    	}
    	else if  (url_exists($resource))
    	{ 
    		$type = 'url';
    		log_message('debug', 'url exists!');
    	}
    	if (isset($type))
    	{
    		//restore error handler to PHP to 'catch' libxml 'errors'
			restore_error_handler();
			libxml_use_internal_errors(true);
			//clear any previous errors
			libxml_clear_errors();
			//load the XML resource into a DOMDocument 
    		$doc = new DOMDocument;
    		$doc->load($resource);
    
    		$errors = libxml_get_errors();
    		
    		//empty errors
			libxml_clear_errors();
			//restore CI error handler
			set_error_handler('_exception_handler');
   		
   			if(!empty($errors))
    			{
    				log_message('error', 'XML/XSL doc load errors: '.json_encode($errors));

    				//see if fatal errors occurred. Return FALSE for doc if one occurred
    				foreach ($errors as $error)// (array_search(LIBXML_ERR_FATAL, (array) $errors) === 'level')
    				{
    				  if ($error->level === 3)
    				  {	
    				  	return array('doc' => FALSE, 'errors' => $errors);
    				  }
    				}
    			}	  			
    		if($doc)
    		{
    			log_message('debug', 'loaded doc!');// xml:'.$doc->saveXML());
    			
                return array('doc' => $doc, 'errors' => $errors, 'type' => $type);     			
    		}
    		else
    		{
    			log_message('error', 'loading XML/XSL doc, errors: '.json_encode($errors)); //xml:'.$xml->saveXML());
    			return FALSE;
    		}
    	}
    	else 
    	{
    		log_message('error', 'could not find file');
    		return FALSE;   	   		
    	}
    }
	
	//returns SimpleXML Object
	private function _xslt_transform($xml, $xsl)
	{
		log_message('debug', 'starting transformation');
		$result = new SimpleXMLElement('<root></root>'); //default
		
		$proc = new XSLTProcessor;
		if (!$proc->hasExsltSupport())
		{
			log_message('error', 'XSLT Processor at server has no EXSLT Support');
		}
		else
		{	
			//restore error handler to PHP to 'catch' libxml 'errors'
			restore_error_handler();
			libxml_use_internal_errors(true);
			//clear any previous errors
			libxml_clear_errors();
			//import XSLT stylesheet
			$proc->importStyleSheet($xsl);
			//$proc->setProfiling(APPPATH.'logs/XSLTprofiling.txt');
			//transform
			$output = $proc->transformToXML($xml);
			$errors = libxml_get_errors();
			//empty errors
			libxml_clear_errors();
			//restore CI error handler
			set_error_handler('_exception_handler');
			
			if($output)
			{		
				$result = simplexml_load_string($output);
				//log_message('debug', 'form:'.$result->saveXML());			
			}
			
			$errors = $this->_error_msg_process($errors);
			$result = $this->_add_errors($errors, 'xsltmessages', $result);			
		}		
		//log_message('debug', 'result:'.$result->asXML());		
		log_message('debug', 'return a result and ending _xslt_transform');
		return $result;						
	}	    
    
    //adds libxml (or similar) errors from array to SimpleXML object root element
    //returns modified SimpleXML object
    private function _add_errors($errors, $el_name, $sxo)
    {
    	if (is_array($errors))
    	{  	
	    	$messages = $sxo->addChild($el_name);
			foreach ($errors as $error)
			{				
			    //$msg = $this -> _msg_process($error);
			    $message = $messages->addChild('message', $error -> message);
			    if (isset($error -> level))
			    {
			    	$message->addAttribute('level', $error -> level);
			    }
			    if (isset($error -> code))
			    {
			    	$message->addAttribute('code', $error -> code);
			    }
			    //if (isset($error->type))
			    //{	
			    //	$message->addAttribute('type', $error -> type);
			   	//}
			    //$message->addAttribute('file', $msg->file);
			    //$message->addAtrribute('line', $msg->line);						
			}
		}
    	return $sxo;
    }
    
        
    //processes an array of libxml errors and looks inside the message text to determine 'type'
    private function _error_msg_process($errors)
    {
    	$type_ind = array(3 =>'FATAL ERROR', 0 => 'INFO', 1 => 'WARNING', 2 => 'ERROR', 10 =>'NO SUPPORT');
    	$type = 'unknown';
    	
    	foreach ($errors as $error_obj)
    	{
    		foreach ($type_ind as $key => $ind)
    		{
    			$pos = stripos($error_obj->message, $ind);
    			//if indicator string is found somewhere in the beginning
    			if ($pos !== FALSE && $pos < 10) 
    			{
    				//$type = strtolower($ind);
    				//all xslt messages are reported as level 2, so need to be adjusted
    				($key === 10) ? $key = 1 : $key = $key ;
    				$level = $key;
    				$error_obj->message = trim(substr($error_obj->message, $pos+strlen($ind)+1));
    				break 1;
    			}
    		}  	
    		//$error_obj -> type = $type;     		
    		if (isset($level))
    		{
    			$error_obj -> level = $level;
    		}
    	}   	
       return $errors;  
    }
     
    //validates javarosa form (replace in future with XML Schema solution)
    private function _odk_validate($xml)
    {    
    	///log_message('debug', 'attempting to use ODK_validate');
      	/////$this->curl->simple_post('https://www.commcarehq.org/formtranslate/validate', array('xform' => $xml));
    	///$xmlstr = $xml->saveXML();
    	///$post = '';
    	///		$url = 'https://www.commcarehq.org/formtranslate/validate';
		///$ch = curl_init();
		///curl_setopt($ch, CURLOPT_URL, $url);
		///curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
		///curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		///curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json; charset=utf-8","Accept:application/json, text/javascript, */*; q=0.01"));
		/////curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		/////curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		///curl_setopt($ch, CURLOPT_POST, TRUE);
		///curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		///$resp = curl_exec($ch); 
		/*$post = '{"xform": "<?xml version="1.0"?><h:html xmlns="http://www.w3.org/2002/xforms" xmlns:h="http://www.w3.org/1999/xhtml" xmlns:ev="http://www.w3.org/2001/xml-events" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:jr="http://openrosa.org/javarosa"><h:head><h:title>Geo Tagger v2</h:title><model><instance><geotagger id="geo_tagger_v2" ><DeviceId/><Image/><Location/><Description/></geotagger></instance><bind nodeset="/geotagger/DeviceId" type="string" jr:preload="property" jr:preloadParams="deviceid"/><bind nodeset="/geotagger/Image" type="binary"/><bind nodeset="/geotagger/Location" type="geopoint"/><bind nodeset="/geotagger/Description" type="string"/></model></h:head><h:body><upload ref="Image" mediatype="image/*"><label>Capture the image.</label></upload><input ref="Location"><label>Capture the location.</label></input><input ref="Description"><label>Describe the image and location.</label></input></h:body></h:html>"}';*/
    	///if (defined($resp))
    	///{
    	///	log_message('debug', 'we have a response: '.$resp);
    	///}
    	///else
    	///{
    	///	log_message('debug', 'no response');
    	///}
    	///log_message('debug', 'curl info: '.json_encode(curl_getinfo($ch)));
    	///
    	///log_message('debug', 'error code: '.curl_getinfo($ch, CURLINFO_HTTP_CODE));
    	///curl_close($ch);
    	$errors = array((object) array('message' => 'This validation is yet not functional.', 'level' => 1));
    	return array('pass' => TRUE, 'errors'=> $errors); //array('valid' => $valid, 'messages' => $messages);
    }
    
     
    //validates html5 form using SOAP (not used any more, instead AJAX from client)
    private function _html5_validate(&$result)
    {
    	//$validation_response = $this->curl->simple_post('http://html5.validator.nu/', json_encode(array('content'=>'bar')));
   		//log_message('debug', 'HTML5 validationresponse: '.jsonencode($validation_response));
    	
    	//$h5_val_fb = $result -> addChild('html5validatormessages');
    	//$message = $h5_val_fb -> addChild('message', 'this validation is not yet functional');
    	//$message -> addAttribute('level', 1);
    	//for testing:
    	//$message = $h5_val_fb -> addChild('message', 'level 2 message (test)');
    	//$message -> addAttribute('level', 2);
    	//$message = $h5_val_fb -> addChild('message', 'level 3 message (test)');
    	//$message -> addAttribute('level', 3);
    	//log_message('debug', 'ending _html5_validate, returning: '.$result->asXML());
    	//return $result;
    }
    
    private function _fix_lang(&$result)
    {
    	//IF PERFORMANCE IS AN ISSUE IT WOULD BE BETTER TO PERFORM THIS WHOLE FUNCTION ON THE ORIGINAL XML DOC
    	$langs = array();
    	
    	if ($result->xpath('/root/form/div[@id="form-languages"]/a'))
    	{
    		foreach ($result->xpath('/root/form/div[@id="form-languages"]/a') as $a)
    		{
	    		//attribute not a string so needs casting
	    		$lang = (string) $a['lang'];
	    		log_message('debug', 'found a element inside div#form-languages with lang='.$lang);
	    		
	    		if (isset($lang) && strlen($lang)>1)
	    		{
	    			$lang_mod = $this->_html5_lang($lang);
	    			//change language name/description in <a> element
	    			log_message('debug', 'changing name in language selector from '.(string) $a.' to '.$lang_mod['lang_name']);
	    			$a->addChild('span', $lang_mod['lang_name']);
	    			//if lang attribute has been modified add to $langs array
	    			if ($lang !== $lang_mod['lang'])
	    			{
	    				$langs[] = array('old_lang' => $lang, 'new_lang'=> $lang_mod['lang']); 
	    			}		 				
	    		}
	    	}	
    	}
    	
    	log_message('debug', 'content of langs array: '.json_encode($langs));
    	
    	$form_languages = $result->xpath('/root/form/div[@id="form-languages"]');
    	$default_lang = '';
    	if (isset($form_languages[0]['data-default-lang']))
    	{
    		$default_lang = (string) ($form_languages[0]['data-default-lang']);
    		log_message('debug', 'default lang defined as: '.$default_lang);
    	}
    	//now iterate $langs array to replace all required lang attributes in $result   	
    	foreach ($langs as $lang_map)
    	{
    		//attribute can be changed through a foreach reference (but not node content can't :P)
    		foreach($result->xpath('/root/form/descendant::*[@lang="'.$lang_map['old_lang'].'"]') as $el)
    		{
    			$el['lang'] = $lang_map['new_lang'];
    		} 
    		if ($default_lang === $lang_map['old_lang'])
    		{   			
    			//the data-default-lang attribute only occurs once 
    			$form_languages[0]['data-default-lang'] = $lang_map['new_lang'];
    			log_message ('debug', 'recognized default lang found: '.$default_lang.' and changed to: '.$lang_map['new_lang']);
    		}    			
    	}
    	
    	//return $result;
    }

    //function to remove comments from $data OBSOLETE, DONE IN XSLT?
    //private function _remove_comments(&$data){
        
    //}

    //function to replace media (img, video audio) urls with urls from the manifest
    private function _fix_media_urls($manifest, &$result){
        log_message('debug', 'going to fix media urls');
        //log_message('debug', 'manifest'.$manifest.asXML());
        if (isset($manifest) && $manifest !== FALSE)
        {
            //$media_arr = array();
            log_message('debug', 'checking mediaFile elements');
            foreach ($manifest->mediaFile as $m)
            {
                //$media_arr[$m->filename] => $m->downloadUrl;
                log_message('debug', 'filename: '.$m->filename);
                log_message('debug', 'downloadUrl: '.$m->downloadUrl);
                foreach ( $result->xpath('/root/form/descendant::*[@src="'.$m->filename.'"]') as $el)
                {
                    $el['src'] = $m->downloadUrl;
                }
                //better to prepend as first child of form (but so easy in jquery...)
                if ($m->filename == 'form_logo.png')
                {
                    $logo = $result->form->section[0]->addChild('img');
                    $logo->addAttribute('src', $m->downloadUrl);
                }
            }


        }
        //return $result;
    }

    //very basic function to create valid html5 lang attributes (and to add language names)
    private function _html5_lang($lang)
    {
    	$lang_name = $lang;
		
		if (strlen($lang) === 2)
		{
			//don't touch lang attribute itself but try to find language name
			$this->db->select('alpha2, name_en');
			$this->db->from('languages');
			$this->db->where('alpha2', $lang);
			$this->db->limit(1);
			$query = $this->db->get();
			
			if ($query->num_rows() > 0 ) 
			{
				$row = $query->row();    					
   				$lang_name = $this->_first_name($row->name_en);
   				log_message('debug', 'found language name: '.$lang_name.' belonging to attribute lang with 2 chars: '.$lang);
			}
		}    	
		else if (strlen($lang) === 3)
		{
			$this->db->select('alpha2, name_en');
			$this->db->from('languages');
			$this->db->where('alpha3_bib', $lang);
			$this->db->or_where('alpha3_ter', $lang);
			$this->db->limit(1);
			$query = $this->db->get();
			
			if ($query->num_rows() > 0 ) 
			{
				$row = $query->row();
				log_message('debug', 'going to transform lang with 3 chars "'.$lang.'"..');
				$lang = $row->alpha2;
				$lang_name = $this->_first_name($row->name_en);
				log_message('debug', '.. into lang "'.$lang.'" with name "'.$lang_name.'"');    	
			}
		}
		else if (strlen($lang) > 3) 
		{
			$query_str= 'SELECT `alpha2`, `name_en`'.
						'FROM (`languages`) '.
						'WHERE `alpha2` LIKE "__" AND `name_en` LIKE "%'.ucfirst(strtolower($lang)).'%" '.
						'OR `alpha2` LIKE "__" AND `name_fr` LIKE "%'.strtolower($lang).'%" '.
						'LIMIT 1';
			$query = $this->db->query($query_str);
			
   		///	$this->db->select('alpha2, name_en');
		///	$this->db->from('languages');
		///	$this->db->like('alpha2', '__', 'none');
		///	//like by itself results in many incorrect answers but like name AND an alpha2 code may be accurate enough
   		///	$this->db->like('name_en',ucfirst(strtolower($lang)));
   		///	//$this->db->and_where('alpha2', 'LIKE "__"');
		///	$this->db->or_like('name_fr',strtolower($lang));
		///	//$this->db->and_where('alpha2', 'LIKE "__"');
		///	$this->db->limit(1);
		///	$query = $this->db->get();
			
			if ($query->num_rows() > 0 ) 
			{
				$row = $query->row();   				
				//probably best to keep lang_name as it is (=$lang)
				log_message('debug', 'going to transform lang  with more than 3 chars"'.$lang.'"..');
				$lang = $row->alpha2;
				log_message('debug', '.. into lang "'.$lang.'" with unchanged name "'.$lang_name.'"');    				
			}   		  	
    	}
    	$last_query = $this->db->last_query();
    	//log_message('debug', 'db query: '.$last_query);
    return array('lang'=>$lang, 'lang_name'=>$lang_name);
    }
    
    //removes alternative options (separated by ';' in database)
    private function _first_name($names)
    {
    	//$end_pos = strpos($name, ';');       	
       	//if ($end_pos > 1)
       	//{
    	//	$name = substr($name, 0, $end_pos);
    	//}
    	$names_arr = explode(";", $names);
    	return trim($names_arr[0]);
    }    
             
}

?>