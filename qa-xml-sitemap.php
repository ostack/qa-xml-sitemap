<?php
/*
	Question2Answer by Gideon Greenspan and contributors
	http://www.question2answer.org/

	File: qa-plugin/xml-sitemap/qa-xml-sitemap.php
	Description: Page module class for XML sitemap plugin


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: http://www.question2answer.org/license.php
*/

class qa_xml_sitemap
{
	public function option_default($option)
	{
		switch ($option) {
			case 'xml_sitemap_show_questions':
			case 'xml_sitemap_show_users':
			case 'xml_sitemap_show_tag_qs':
			case 'xml_sitemap_show_category_qs':
			case 'xml_sitemap_show_categories':
				return true;
			case 'max_num_in_one_xml':	
			    return 20000;
		}
	}


	public function admin_form()
	{
		require_once QA_INCLUDE_DIR . 'util/sort.php';

		$saved = false;

		if (qa_clicked('xml_sitemap_save_button')) {
			qa_opt('max_num_in_one_xml',(int)qa_post_text('max_num_in_one_xml'));
			qa_opt('xml_sitemap_show_questions', (int)qa_post_text('xml_sitemap_show_questions_field'));
			
			if (!QA_FINAL_EXTERNAL_USERS)
				qa_opt('xml_sitemap_show_users', (int)qa_post_text('xml_sitemap_show_users_field'));

			if (qa_using_tags())
				qa_opt('xml_sitemap_show_tag_qs', (int)qa_post_text('xml_sitemap_show_tag_qs_field'));

			if (qa_using_categories()) {
				qa_opt('xml_sitemap_show_category_qs', (int)qa_post_text('xml_sitemap_show_category_qs_field'));
				qa_opt('xml_sitemap_show_categories', (int)qa_post_text('xml_sitemap_show_categories_field'));
			}
            
			$saved = true;
		}elseif (qa_clicked('donate_zhao_guangyue')) {
				qa_redirect_raw('https://paypal.me/guangyuezhao');
		}

		$form = array(
			'ok' => $saved ? 'XML sitemap settings saved' : null,

			'fields' => array(
			    'max_num_in_one_xml'=>array(
					'label' => 'Max number in one site map xml file:',
					'tags' => 'NAME="max_num_in_one_xml"',
					'value' => qa_opt('max_num_in_one_xml'),
					'type' => 'number',
				),
				'questions' => array(
					'label' => 'Include question pages',
					'type' => 'checkbox',
					'value' => (int)qa_opt('xml_sitemap_show_questions'),
					'tags' => 'name="xml_sitemap_show_questions_field"',
				),
				
			),

			'buttons' => array(
				array(
					'label' => 'Save Changes',
					'tags' => 'name="xml_sitemap_save_button"',
				),
				array(
						'label' => 'Donate',
						'tags' => 'NAME="donate_zhao_guangyue"',
				)
			),
		);

		if (!QA_FINAL_EXTERNAL_USERS) {
			$form['fields']['users'] = array(
				'label' => 'Include user pages',
				'type' => 'checkbox',
				'value' => (int)qa_opt('xml_sitemap_show_users'),
				'tags' => 'name="xml_sitemap_show_users_field"',
			);
		}

		if (qa_using_tags()) {
			$form['fields']['tagqs'] = array(
				'label' => 'Include question list for each tag',
				'type' => 'checkbox',
				'value' => (int)qa_opt('xml_sitemap_show_tag_qs'),
				'tags' => 'name="xml_sitemap_show_tag_qs_field"',
			);
		}

		if (qa_using_categories()) {
			$form['fields']['categoryqs'] = array(
				'label' => 'Include question list for each category',
				'type' => 'checkbox',
				'value' => (int)qa_opt('xml_sitemap_show_category_qs'),
				'tags' => 'name="xml_sitemap_show_category_qs_field"',
			);

			$form['fields']['categories'] = array(
				'label' => 'Include category browser',
				'type' => 'checkbox',
				'value' => (int)qa_opt('xml_sitemap_show_categories'),
				'tags' => 'name="xml_sitemap_show_categories_field"',
			);
		}

		return $form;
	}


	public function suggest_requests()
	{
		return array(
			array(
				'title' => 'XML Sitemap',
				'request' => 'sitemap.html',
				'nav' => null, // 'M'=main, 'F'=footer, 'B'=before main, 'O'=opposite main, null=none
			),
		);
	}


	public function match_request($request)
	{
		return ($request == 'sitemap.html');
	}


	public function process_request($request)
	{
		//@ini_set('display_errors', 0); // we don't want to show PHP errors inside XML
		$this->site_map_question();
		$this->site_map_category();
		$this->site_map_users();
		$this->site_map_tags();
		$this->site_map_question_in_category();
		
		return null;
	}

	private function sitemap_output($request, $priority)
	{
		return "\t<url>\n" .
			"\t\t<loc>" . qa_xml(qa_path($request, null, qa_opt('site_url'))) . "</loc>\n" .
			"\t\t<priority>" . max(0, min(1.0, $priority)) . "</priority>\n" .
			"\t</url>\n";
	}
	
	private function get_xml_header(){
		$encode= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$start_urlset='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		return $encode.$start_urlset;
	}
	
	private function get_xml_end(){
		return "</urlset>\n";
	}
	
	private function site_map_question(){
		if (qa_opt('xml_sitemap_show_questions')) {
			$nextpostid = 0;
			$wite_url_num  = 0;
			$open_file_info=array();
			$open_file_info['file_path']="";
			$open_file_info['open_file']=null;
			//print_r( $open_file_info);
			echo 'Question site maps:'."<br>";
			$hotstats = qa_db_read_one_assoc(qa_db_query_sub(
				"SELECT MIN(hotness) AS base, MAX(hotness)-MIN(hotness) AS spread FROM ^posts WHERE type='Q'"
			));
	
			while (1) {
				$open_file_info=$this->open_or_close_file($open_file_info,$wite_url_num,'question');
				$questions = qa_db_read_all_assoc(qa_db_query_sub(
					"SELECT postid, title, hotness FROM ^posts WHERE postid># AND type='Q' ORDER BY postid LIMIT 10",
					$nextpostid
				));

				if (!count($questions)){
		            $this->write_end_and_close_file($open_file_info);
					break;
				}
			
				foreach ($questions as $question) {
					$url_xml = $this->sitemap_output(qa_q_request($question['postid'], $question['title']),
						0.1 + 0.9 * ($question['hotness'] - $hotstats['base']) / (1 + $hotstats['spread']));
					$this->appen_content($open_file_info,$url_xml);
					$nextpostid = max($nextpostid, $question['postid']);
					$wite_url_num= $wite_url_num+1;
				}
			} 
		}
	}
	
	private function site_map_users(){
		if (!QA_FINAL_EXTERNAL_USERS && qa_opt('xml_sitemap_show_users')) {
			$nextuserid = 0;
			$wite_url_num  = 0;
			$open_file_info=array();
			$open_file_info['file_path']="";
			$open_file_info['open_file']=null;
			echo 'User site maps:'."<br>";
			while (1) {
				$users = qa_db_read_all_assoc(qa_db_query_sub(
					"SELECT userid, handle FROM ^users WHERE userid># ORDER BY userid LIMIT 100",
					$nextuserid
				));
				
                $open_file_info=$this->open_or_close_file($open_file_info,$wite_url_num,'user');
				
				if (!count($users)){
					$this->write_end_and_close_file($open_file_info);
					break;
				}
				
				foreach ($users as $user) {
					$url_xml = $this->sitemap_output('user/' . $user['handle'], 0.25);
					$this->appen_content($open_file_info,$url_xml);
					$wite_url_num= $wite_url_num+1;
					$nextuserid = max($nextuserid, $user['userid'] );
					
				}
			}
		}
	}
	
	private function site_map_tags(){
		// Tag pages
		if (qa_using_tags() && qa_opt('xml_sitemap_show_tag_qs')) {
			$nextwordid = 0;
			$wite_url_num  = 0;
			$open_file_info=array();
			$open_file_info['file_path']="";
			$open_file_info['open_file']=null;
			echo 'tags site maps:'."<br>";
			while (1) {
				$tagwords = qa_db_read_all_assoc(qa_db_query_sub(
					"SELECT wordid, word, tagcount FROM ^words WHERE wordid> # AND tagcount>0 ORDER BY wordid LIMIT 100",
					$nextwordid
				));
				
				$open_file_info=$this->open_or_close_file($open_file_info,$wite_url_num,'tags');
				
				if (!count($tagwords)){
					$this->write_end_and_close_file($open_file_info);
					break;
				}

				foreach ($tagwords as $tagword) {
					$url_xml = $this->sitemap_output('tag/' . $tagword['word'], 0.5 / (1 + (1 / $tagword['tagcount']))); // priority between 
					$this->appen_content($open_file_info,$url_xml);
					$wite_url_num= $wite_url_num+1;
					$nextwordid = max($nextwordid, $tagword['wordid']);
				}
			}
		}
	}
	
	private function site_map_question_in_category(){
		// Question list for each category
		if (qa_using_categories() && qa_opt('xml_sitemap_show_category_qs')) {
			$nextcategoryid = 0;
            $wite_url_num  = 0;
			$open_file_info=array();
			$open_file_info['file_path']="";
			$open_file_info['open_file']=null;
			echo 'Question in categories site maps:'."<br>";
			while (1) {
				$categories = qa_db_read_all_assoc(qa_db_query_sub(
					"SELECT categoryid, backpath FROM ^categories WHERE categoryid># AND qcount>0 ORDER BY categoryid LIMIT 10",
					$nextcategoryid
				));
				
				$open_file_info=$this->open_or_close_file($open_file_info,$wite_url_num,'question_in_category');
				
				if (!count($categories)){
					$this->write_end_and_close_file($open_file_info);
					break;
				}
				
				foreach ($categories as $category) {
					$url_xml =$this->sitemap_output('questions/' . implode('/', array_reverse(explode('/', $category['backpath']))), 0.5);
					$this->appen_content($open_file_info,$url_xml);
					$wite_url_num= $wite_url_num+1;
					$nextcategoryid = max($nextcategoryid, $category['categoryid']);
				}
			}
		}
	}
	
	private function site_map_category(){
		// Pages in category browser

		if (qa_using_categories() && qa_opt('xml_sitemap_show_categories')) {
		
			$nextcategoryid = 0;
			$wite_url_num  = 0;
			$open_file_info=array();
			$open_file_info['file_path']="";
			$open_file_info['open_file']=null;
			echo 'Category site maps:'."<br>";
			while (1) { // only find categories with a child
				$categories = qa_db_read_all_assoc(qa_db_query_sub(
					"SELECT parent.categoryid, parent.backpath FROM ^categories AS parent " .
					"JOIN ^categories AS child ON child.parentid=parent.categoryid WHERE parent.categoryid>=# GROUP BY parent.categoryid LIMIT 100",
					$nextcategoryid
				));
				
				$open_file_info=$this->open_or_close_file($open_file_info,$wite_url_num,'category');
				
				if (!count($categories)){
					$this->write_end_and_close_file($open_file_info);
					break;
				}

				foreach ($categories as $category) {
					$url_xml = $this->sitemap_output('categories/' . implode('/', array_reverse(explode('/', $category['backpath']))), 0.5);
					$this->appen_content($open_file_info,$url_xml);
					$wite_url_num= $wite_url_num+1;
					$nextcategoryid = max($nextcategoryid, $category['categoryid'] + 1);
				}
			}
		}
	}
	
	
	private function get_write_file($postid,$type){
		//echo $postid.',max_num_in_one_xml:'.qa_opt('max_num_in_one_xml').'<br>';
		$path= QA_BASE_DIR.'site_map_'.$type.'_'.intval($postid/qa_opt('max_num_in_one_xml')).'.xml';
		//echo $path;
		return $path;
	}
	
	private function open_or_close_file($open_file_info,$nextpostid,$type){
		$file_path = $open_file_info['file_path'];
		$open_file = $open_file_info['open_file'];
		$new_path = $this->get_write_file($nextpostid,$type);
		if($file_path != $new_path){
            $this->write_end_and_close_file($open_file_info);
			$new_file = @fopen($new_path, "w");
			$open_file_info['file_path']=$new_path;
			$open_file_info['open_file']=$new_file;
			$this->appen_content($open_file_info,$this->get_xml_header());
		}
		return $open_file_info;
	}
	
	private function write_end_and_close_file($open_file_info){
		$file_path = $open_file_info['file_path'];
		$open_file = $open_file_info['open_file'];
		if($open_file!=null){
			$siteMapUrl = qa_opt('site_url').basename($file_path);
			echo "<a href =".$siteMapUrl.">".$siteMapUrl."</a><br>";
			$this->appen_content($open_file_info,$this->get_xml_end());
			fclose($open_file);
			$open_file_info['file_path']="";
			$open_file_info['open_file']=null;
		}
	}
	
	private function appen_content($open_file_info,$content){
		$open_file = $open_file_info['open_file'];
		if($open_file!=null){
			@fwrite($open_file,$content);
		}
	}
}
