<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * ExpressionEngine - by EllisLab
 *
 * @package		ExpressionEngine
 * @author		ExpressionEngine Dev Team
 * @copyright	Copyright (c) 2003 - 2011, EllisLab, Inc.
 * @license		http://expressionengine.com/user_guide/license.html
 * @link		http://expressionengine.com
 * @since		Version 2.0
 * @filesource
 */
 
// ------------------------------------------------------------------------

/**
 * dashEE Module Control Panel File
 *
 * @package		ExpressionEngine
 * @subpackage	Addons
 * @category	Module
 * @author		Chris Monnat
 * @link		http://chrismonnat.com
 */

class Dashee_mcp {
	
	public $return_data;
	
	private $_EE;
	private $_model;
	private $_base_qs;
	private $_base_url;
	private $_theme_url;
	private $_member_id;
	private $_settings;
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->_EE =& get_instance();
		
        $this->_EE->load->model('dashee_model');
        $this->_model = $this->_EE->dashee_model;
		
        $this->_base_qs     = 'C=addons_modules' .AMP .'M=show_module_cp' .AMP .'module=dashee';
        $this->_base_url    = BASE .AMP .$this->_base_qs;
        $this->_theme_url   = $this->_model->get_package_theme_url();
        
        $this->_member_id = $this->_EE->session->userdata('member_id');
        
        // get current members dash configuration for use throughout module
        $this->_get_member_settings($this->_member_id);
	}
	
	// ----------------------------------------------------------------

	/**
	 * Index Function
	 *
	 * @return 	void
	 */
	public function index()
	{
        $css = $this->_theme_url .'css/cp.css';
		$js  = $this->_theme_url .'js/dashee.js';
		
        $this->_EE->cp->add_to_head('<link rel="stylesheet" type="text/css" href="'.$css.'" />');
        $this->_EE->cp->add_to_head('<script type="text/javascript" src="'.$js.'"></script>');
	
		$this->_EE->cp->set_variable('cp_page_title', lang('dashee_term'));
		
		$this->_EE->cp->set_right_nav(array('btn_collapse' => '#collapse', 'btn_expand' => '#expand', 'btn_widgets' => '#widgets'));
		
		// load widgets
		$widgets = $this->_widget_loader($this->_settings['widgets']);
		
		return $this->_EE->load->view('index', array('settings' => $this->_settings, 'content' => $widgets, 'theme_url' => $this->_theme_url), TRUE);
	}
	
	/**
	 * AJAX METHOD
	 * Get listing of all available widgets from installed modules.
	 *
	 * @return 	NULL
	 */
	public function get_widget_listing()
	{
		$this->_EE->load->library('table');
	
		$map = directory_map(PATH_THIRD, 2);
		
		// Fetch installed modules.
		$installed_mods = $this->_model->get_installed_modules();
		
		// Determine which installed modules have widgets associated with them.
		$mods_with_widgets = array();
		foreach($map as $third_party => $jabber)
		{
			if(is_array($jabber))
			{
				if(in_array($third_party, $installed_mods) AND in_array('widgets', $jabber))
				{
					$mods_with_widgets[] = $third_party;
				}
			}
		}
		
		// Get array of all widgets of installed modules.
		$table_data = array();
		asort($mods_with_widgets);
		foreach($mods_with_widgets as $mod)
		{
			$path = PATH_THIRD.$mod.'/widgets/';
			$map = directory_map(PATH_THIRD.$mod.'/widgets', 1);
		
			if(is_array($map))
			{
				$col = 1;
				asort($map);
				foreach($map as $widget)
				{
					$this->_EE->lang->loadfile($mod);
					
					// check widget permissions before adding to table and skip if user doesn't have permission
					$obj = $this->_get_widget_object($mod, $widget);
					if(method_exists($obj, 'permissions') && !$obj->permissions())
					{
						continue;
					}
					
					$table_data[] = array(
						lang($this->_format_filename($widget).'_name'),
						lang($this->_format_filename($widget).'_description'),
						lang(strtolower($mod).'_module_name'),
						anchor($this->_base_url.AMP.'method=add_widget'.AMP.'mod='.$mod.AMP.'wgt='.$widget, 'Add')
						);			
				}
			}
		}
		
		echo $this->_EE->load->view('widgets_listing', array('rows' => $table_data), TRUE);
		exit();
	}
	
	/**
	 * Add Widget's Package Path
	 *
	 * Makes it possible for widgets to use $EE->load->view(), etc
	 *
	 * Should be called right before calling a widget's index() funciton
	 */
	private function _add_widget_package_path($name)
	{
		$path = PATH_THIRD . $name . '/';
		$this->_EE->load->add_package_path($path);

		// manually add the view path if this is less than EE 2.1.5
		if (version_compare(APP_VER, '2.1.5', '<'))
		{
			$this->_EE->load->_ci_view_path = $path . 'views/';
		}
	}
	
	/**
	 * Add selected widget to users dashboard and update config.
	 *
	 * @return 	void
	 */
	public function add_widget()
	{
		$mod = $this->_EE->input->get('mod');
		$wgt = $this->_EE->input->get('wgt');

		if(isset($mod) AND isset($wgt))
		{
			$obj = $this->_get_widget_object($mod, $wgt);
			
			// determine which column has the least number of widgets in it so you can add the 
			// new one to the one with the least
			$totals 	= array();
			$totals[1] 	= @count($this->_settings['widgets'][1]);
			$totals[2] 	= @count($this->_settings['widgets'][2]);
			$totals[3] 	= @count($this->_settings['widgets'][3]);	
			$col 		= array_keys($totals, min($totals));
		
			$new_widget = array(
				'mod' => $mod,
				'wgt' => $wgt,				
				);
		
			// add widget settings to config if present
			if(isset($obj->settings))
			{
				$new_widget['stng'] = json_encode($obj->settings);
			}
			
			$this->_settings['widgets'][$col[0]][] = $new_widget;
			
			// update members dashboard config in DB
			$this->_update_member();
		}
		
		$this->_EE->session->set_flashdata('message_success', lang('widget_added'));
		$this->_EE->functions->redirect($this->_base_url);
	}
	
	/**
	 * AJAX METHOD
	 * Remove selected widget from users dashboard and update settings.
	 *
	 * @return 	NULL
	 */
	public function remove_widget()
	{
		$col = $this->_EE->input->get('col');
		$wgt = $this->_EE->input->get('wgt');

		if(isset($col) AND isset($wgt))
		{
			unset($this->_settings['widgets'][$col][$wgt]);
			$this->_update_member(FALSE);
		}
	}
	
	/**
	 * AJAX METHOD
	 * Update widget order and column placement in DB.
	 *
	 * @return 	NULL
	 */
	public function update_widget_order()
	{
		$order = $this->_EE->input->get('order');
		
		if($order)
		{
			$widgets		= explode('|', $order);
			$current 		= $this->_settings['widgets'];
			$widgets_only 	= array();
			$new			= array();
			
			// get widget only settings in accessable array (without column number in front)
			foreach($current as $col => $wgts)
			{
				foreach($wgts as $id => $settings)
				{
					$widgets_only[$id] = $settings;
				}
			}
			
			// loop through widgets, separate based on delimiter and create new array based on submitted 
			foreach($widgets as $widget)
			{
				$pieces = explode(':', $widget);
				$new[$pieces[0]][$pieces[1]] = $widgets_only[$pieces[1]];
			}
			
			$this->_settings['widgets'] = $new;
			$this->_update_member(FALSE);
		}
		
		return TRUE;
	}
	
	/**
	 * AJAX METHOD
	 * Display settings options for selected widget.
	 *
	 * @return 	NULL
	 */
	public function widget_settings()
	{
		$col = $this->_EE->input->get('col');
		$wgt = $this->_EE->input->get('wgt');

		if(isset($col) AND isset($wgt))
		{
			$widget = $this->_settings['widgets'][$col][$wgt];
			
			$obj = $this->_get_widget_object($widget['mod'],$widget['wgt']);
			echo $obj->settings_form(json_decode($widget['stng']));
			exit();
		}
	}
	
	/**
	 * AJAX METHOD
	 * Attempt to update a widgets settings.
	 *
	 * @return 	NULL
	 */
	public function update_settings()
	{
		$data 		= $_POST;
		$settings 	= array();
		$widget 	= $this->_settings['widgets'][$data['col']][$data['wgt']];
				
		foreach($data as $field => $value)
		{
			$settings[$field] = $value;
		}
	
		$settings_json = json_encode($settings);
		$this->_settings['widgets'][$data['col']][$data['wgt']]['stng'] = $settings_json;
		$this->_update_member(FALSE);
	
		$obj = $this->_get_widget_object($widget['mod'],$widget['wgt']);
		$this->_add_widget_package_path($widget['mod']);
		$content = $obj->index(json_decode($settings_json));
		$result = array(
			'title'		=> $obj->title,
			'content' 	=> $content
			);
		echo json_encode($result);
		exit();
	}
	
	/**
	 * Get/update users dashEE settings.
	 *
	 * @return 	array
	 */
	public function _get_member_settings($member_id)
	{
		$settings = $this->_model->get_member_settings($member_id);

		$this->_EE->cp->get_installed_modules();

		// Ensure all widgets in users settings are still installed and files available.
		$update_member = FALSE;
		foreach($settings['widgets'] as $col => $widget)
		{
			if(is_array($widget))
			{
				foreach($widget as $id => $params)
				{
					if(!isset($this->_EE->cp->installed_modules[$params['mod']]) || 
						!file_exists(PATH_THIRD.$params['mod'].'/widgets/'.$params['wgt']))
					{
						unset($settings['widgets'][$col][$id]);
						
						$update_member = TRUE;
					}
				}
			}
		}
		
		$this->_settings = $settings;
	
		if($update_member)
		{
			$this->_update_member();
		}
	}
	
	/**
	 * Attempt to update a members dashboard config in DB.
	 *
	 * @return 	array
	 */
	private function _update_member($reindex = TRUE)
	{
		if($reindex)
		{
			// reindex widgets array before saving it to the DB
			$i = 1;
			$widgets = array(1 => array(), 2 => array(), 3 => array());
			foreach($this->_settings['widgets'] as $col => $widget)
			{
				if(is_array($widget))
				{
					foreach($widget as $id => $params)
					{
						$widgets[$col]['wgt'.$i] = $params;
						++$i;
					}
				}
			}
			$this->_settings['widgets'] = $widgets;
		}

		$this->_model->update_member($this->_member_id, $this->_settings);	
	}

	/**
	 * Load selected widgets for display.
	 *
	 * @return 	array
	 */
	private function _widget_loader(array $widgets)
	{
		$cols = array(1 => '', 2 => '', 3 => '');

		foreach($widgets as $col => $widget)
		{
			if(is_array($widget))
			{
				foreach($widget as $id => $params)
				{
					$obj = $this->_get_widget_object($params['mod'], $params['wgt']);
									
					$class 		= isset($obj->wclass) ? $obj->wclass : '';
					$dash_code 	= method_exists($obj, 'settings_form') ? 'dashee="dynamic"' : '';

					// check widget permissions
					if(method_exists($obj, 'permissions') && !$obj->permissions())
					{
						$content = '<p>'.lang('permission_denied').'</p>';
					}
					else
					{
						$this->_add_widget_package_path($params['mod']);
						$content = $obj->index(@json_decode($params['stng']));
					}
					
					$cols[$col] .= '
						<li id="'.$id.'" class="widget '.$class.'" '.$dash_code.'>
							<div class="heading">
								<h2>'.$obj->title.'</h2>
								<div class="buttons"></div>
							</div>
							<div class="widget-content">'.$content.'</div>
						</li>
					';
				}
			}

			$cols[$col] .= '&nbsp;';
		}
		
		return $cols;
	}
	
	/**
	 * Require necessary widget class and return instance.
	 *
	 * @param	$module		string		Module that requested widget is part of.
	 * @param	$widget		string		Requested widget.
	 * @return 	object
	 */
	private function _get_widget_object($module, $widget)
	{
		include_once(PATH_THIRD.$module.'/widgets/'.$widget);
		$obj = $this->_format_filename($widget, TRUE);
		return new $obj();
	}
	
	/**
	 * Format widget names for reference.
	 *
	 * @param 	$name		string		File name.
	 * @param 	$cap		bool		Capitalize filename?
	 * @return 	string
	 */
	private function _format_filename($name, $cap = FALSE)
	{
		$str = str_replace('.', '_', substr($name, 0, -4));
		return $cap ? ucfirst($str) : $str;
	}
	
}
/* End of file mcp.dashee.php */
/* Location: /system/expressionengine/third_party/dashee/mcp.dashee.php */