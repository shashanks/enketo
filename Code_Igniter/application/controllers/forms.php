<?php

/**
 * Copyright 2012 Martijn van de Rijdt
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class Forms extends CI_Controller {

	function __construct()
	{
		parent::__construct();
	}

	public function index()
	{
		$this->load->helper(array('url', 'subdomain'));

		if (get_subdomain())
		{
			show_404();
		}

		$default_scripts = array
		(
			'/libraries/jquery.min.js',
			'/libraries/bootstrap/js/bootstrap.min.js',
			'/libraries/modernizr.min.js'//,
			//'/libraries/fastclick/lib/fastclick.js'
		);

		$default_stylesheets = array
		(
			array( 'href' => '/css/forms.css', 'media' => 'screen')
		);

		$data = array(
			//'manifest'=> '/manifest/html/forms', //issue with authentication when making this offline-enabled
			'title_component'=>'forms', 
			'robots'=>TRUE,
			'stylesheets' => $default_stylesheets
		);

		if (ENVIRONMENT === 'production')
		{
			$data['scripts'] = array_merge($default_scripts, array(
				'/js-min/formlist-all-min.js'
			));
		}
		else
		{
			$data['scripts'] = array_merge($default_scripts, array(
				'/js-source/helpers.js',
				'/js-source/debug.js',
				'/js-source/gui.js',       
				'/js-source/storage.js',
       			'/js-source/connection.js',
       			'/js-source/cache.js',
        		'/js-source/formlist.js'
			));
		}
		$this->output->cache(10);
		$this->load->view('formlist_view', $data);
	}

	public function get_list()
	{
		if ($this->config->item('auth_support'))
		{
			$this->load->add_package_path(APPPATH.'third_party/form_auth');
		}
		$this->load->library('form_auth');
		$server_url = $this->input->get('server_url', TRUE);

		if ($server_url && strlen($server_url) > 0)
		{
			$this->load->model('Form_model', '');
			$credentials = $this->form_auth->get_credentials();
			$this->Form_model->setup($server_url, NULL, $credentials);

			if($this->Form_model->requires_auth())
			{
				log_message('debug', 'AUTHENTICATION REQUIRED');
				$this->output
					->set_status_header('401', 'Unauthorized');
					//->set_output('authenticate');
			}
			else
			{
				log_message('debug', 'auth not required');
				$result = $this->Form_model->get_formlist_JSON($server_url);
				$this->output
					->set_content_type('application/json')
					->set_output(json_encode($result));
			}
		}
		else 
		{
			echo 'no server url received!';
		}
	}

}


?>