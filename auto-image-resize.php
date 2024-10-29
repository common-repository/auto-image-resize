<?php
/*
Plugin Name: Auto Image Resize
Text Domain: auto-image-resize
Description: You will be able to resize smartphone, tablet PC for the image in the article. This Plugin is the ideal tool for Responsive Web design.
Version: 1.0.1
Author: Kiminori Shimada
Author URI: http://wiz-art.net
License: 
    Copyright 2012- kiminori shimada : shimada@wiz-art.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


require_once dirname(__FILE__) . '/modules/class.image.php';

class AIResize {
	/* デフォルトサイズ */
	var $defaultsettings = array(
		'mobile_width' => 480,
		'tablet_width' => 768
	);
	
	function __construct(){
		$this->view_type = $this->get_user_view_type();
		
		add_action( 'init', array( &$this, 'init_action') );
		add_action( 'admin_menu', array( &$this, 'plugin_menu') );
		add_filter( 'wp_handle_upload', array( &$this, 'another_save_file') );
		add_filter( 'plugin_action_links', array( &$this, 'settings_link'), 10, 2);
		add_action( 'admin_init', array( &$this, 'register_setting' ) );
		add_filter( 'get_image_tag', array( &$this, 'airesize_tag_replace' ), 1, 6);
		
		//add_action( 'wp_head', array( &$this, 'header_first'), 5);
		//add_action( 'wp_head', array( &$this, 'header_ready'), 12);
		
		// テンプレートタグ設定
		add_shortcode('airesizeimg', array( &$this, 'image_tag_replace'));
		
		//add_filter('the_content', array( &$this, 'image_tag_replaces'));

		$usersettings = (array) get_option('airesize_settings');
		$this->settings = wp_parse_args( $usersettings, $this->defaultsettings );
	}

	/* プラグイン一覧画面・設定リンク */
	function settings_link( $links, $file ) {
		static $this_plugin;

		if( empty($this_plugin) )
			$this_plugin = plugin_basename(__FILE__);

		if ( $file == $this_plugin )
			$links[] = '<a href="' . admin_url( 'options-general.php?page=airesize' ) . '">' . __( 'Settings', 'auto-image-resize' ) . '</a>';

		return $links;
	}

	/* プラグインオプション */
	function plugin_menu() {
		add_options_page( __( 'SettingsPageTitle', 'auto-image-resize' ), 'AutoImageResize', 'manage_options', 'airesize', array( &$this, 'plugin_options') );
	}

	/* 初期化アクションフック */
	function init_action() {
		//言語ファイル読み込み
		load_plugin_textdomain( 'auto-image-resize', false, basename( rtrim(dirname(__FILE__), '/')).'/languages' );
	}

	function another_save_file($file) {
		// 画像アップロード時に画像を生成しておく

		$this->delete_files( $file['file'] );
		$this->create_files( $file['file'] );

		return $file;
	}
	
	function create_files( $file_path ){
		// モバイル用
		$newname = $this->get_imagename($file_path, 'mobile');
		if( !file_exists( $newname ) ){
			copy($file_path, $newname);	/* 上書きをラッパーが許可していない場合は、失敗する場合があるらしい・・ */

			$resizeimg = new Image($newname);
			if( $this->settings['mobile_width'] < $resizeimg->getBaseWidth() ){
				$resizeimg->width($this->settings['mobile_width']);
				$resizeimg->save();
			}
		}
		
		// タブレット用
		$newname = $this->get_imagename($file_path, 'tablet');
		if( !file_exists( $newname ) ){
			copy($file_path, $newname);

			$resizeimg = new Image($newname);
			if( $this->settings['tablet_width'] < $resizeimg->getBaseWidth() ){
				$resizeimg->width($this->settings['tablet_width']);
				$resizeimg->save();
			}
		}
	}
	
	function delete_files( $file_path ){
		// モバイル用
		$delname = $this->get_imagename($file_path, 'mobile');
		if( file_exists( $delname ) ){
			unlink($delname);
		}
		
		// タブレット用
		$delname = $this->get_imagename($file_path, 'tablet');
		if( file_exists( $delname ) ){
			unlink($delname);
		}
	}

	function get_imagename($name, $key) {
		//$names = pathinfo($name);
		//return $names['dirname'].'/'.$names['filename'].'_'.$key.'.'.$names['extension'];
		$names = mb_split("\/",$name);
		
		preg_match('/(\.gif$|\.png$|\.jpg$|\.jpeg$|\.bmp$)/i', end($names), $matches);
		$file['extension'] = $matches[1];
		
		$file['filename'] = preg_replace('/'.$file['extension'].'$/i', '', end($names));
		
		return dirname($name).'/'.$file['filename'].'_'.$key.$file['extension'];
	}
	
	function airesize_tag_replace($html, $id, $alt, $title, $align, $size){

		$html = str_replace('<img ', '[airesizeimg ', $html);
		$html = str_replace('/>', ']', $html);
		
		$html = preg_replace('/ width="([^"]+)"/i', '', $html);
		$html = preg_replace('/ height="([^"]+)"/i', '', $html);

		// データベースから画像情報取得
		global $wpdb;
		$sql = <<< HERE
		SELECT * FROM $wpdb->posts
		WHERE ID = $id
HERE;
		$image_info = $wpdb->get_results($sql);
		$image_info = $image_info[0];
		
		$match_path = substr( $image_info->guid, 0, strrpos($image_info->guid, '.') );
	
		// サイズ部分を＄で区切る
		$html = preg_replace('/'.preg_quote($match_path,'/').'(-[0-9]+x[0-9]+)/u', $match_path.'$\1$', $html);
		
		// トリミング等編集をした際はハイフンでユニークコードがふられるので、その時の対応
		$html = preg_replace('/'.preg_quote($match_path,'/').'(-[^-]+)(-[0-9]+x[0-9]+)/u', $match_path.'\1$\2$', $html);
		
		
		preg_match('/'.preg_quote($match_path,'/').'(-[^-\$\."]+)/u', $html, $matchs);
		if( isset($matchs[1]) ){
			$create_img_url = wordwrap($image_info->guid, strrpos($image_info->guid, '.'), $matchs[1], true);
		}
		else{
			$create_img_url = $image_info->guid;
		}
		//FB::info($create_img_url);
		
		// モバイル・タブレット用画像作成
		$upload_info = wp_upload_dir();
		$image_path = str_replace($upload_info['baseurl'], $upload_info['basedir'], $create_img_url);
		//FB::info($image_path);
		$this->create_files( $image_path );
		
		return $html;
	}
	
	function image_tag_replace($atts){
		
		if( isset($atts['src']) ){
			if( $this->view_type == 0 ){
				$atts['src'] = preg_replace('/\$([^\$]+)\$/', '\1', $atts['src']);
			}
			else{
				$atts['src'] = preg_replace('/\$[^\$]+\$/', '', $atts['src']);

				if( $this->view_type == 1 ){
					$atts['src'] = wordwrap($atts['src'], strrpos($atts['src'], '.'), '_tablet', true);
				}
				else if( $this->view_type == 2 ){
					$atts['src'] = wordwrap($atts['src'], strrpos($atts['src'], '.'), '_mobile', true);
				}
			}
		}
		
		$ret_str = '<img ';
		foreach( $atts as $key => $val ){
			if( is_numeric($key) ){
				
				if( substr($val, 0, 1) === '@' ){
					//前バージョンとの互換
					
					if( $this->view_type == 0 ){
						$val2 = preg_replace('/([^\$\]]*)\$([^\$\]]+)\$([^\]]+)/', '\1\2\3', $val);

						$key2 = '';
					}
					else{
						$val2 = preg_replace('/([^\$\]]*)\$[^\$\]]+\$([^\]]+)/', '\1\2', $val);

						if( $this->view_type == 1 ){
							$key2 = '_tablet';
						}
						else if( $this->view_type == 2 ){
							$key2 = '_mobile';
						}
					}

					$ret_str .= preg_replace('/@\{([^\}]+)\}\{([^\}\]]+)\}/', 'src="\1'.$key2.'\2" ', $val2);
				}
				else{
					$ret_str .= $val.' ';
				}
				
			}
			else{
				$ret_str .= $key.'="'.$val.'" ';
			}
		}
		$ret_str .= '/>';
		
		return $ret_str;
	}
	
	/* PC・スマホ・iphone判別（簡易） */
	function get_user_view_type(){
		//ユーザーエージェントを取得
		$user_agent = getenv("HTTP_USER_AGENT");

		if(ereg("Android",$user_agent)){
			if(ereg("Mobile",$user_agent)){
				//スマホ
				$view_type = 2 ;
			} else {
				//タブレット
				$view_type = 1 ;
			}
		} elseif(ereg("iPad",$user_agent)){
			$view_type = 1 ;
		} elseif(ereg( "iPhone",$user_agent)){
			$view_type = 2 ;
		} else {
			// PC
			$view_type = 0 ;
		}

		return $view_type;
	}

	function register_setting() {
		register_setting( 'airesize_settings', 'airesize_settings', array( &$this, 'validate_settings') );
	}
	
	function validate_settings( $settings ) {
		$settings['mobile_width'] = mb_ereg_replace('[^0-9]', '', ( ! empty($settings['mobile_width'])) ? $settings['mobile_width'] : '');
		$settings['tablet_width'] = mb_ereg_replace('[^0-9]', '', ( ! empty($settings['tablet_width'])) ? $settings['tablet_width'] : '');
		
		$settings['mobile_width'] = ( ! empty($settings['mobile_width'])) ? $settings['mobile_width'] : $this->defaultsettings['mobile_width'];
		$settings['tablet_width'] = ( ! empty($settings['tablet_width'])) ? $settings['tablet_width'] : $this->defaultsettings['tablet_width'];
		
		if( !empty($settings['image_init']) && $settings['image_init'] === 'on' ){
			// 既存の画像を書き換える
			set_time_limit( 0 );

			global $wpdb;
			$sql = <<< HERE
			SELECT a.* FROM $wpdb->posts a
			WHERE a.post_mime_type LIKE 'image/%'
			AND a.post_type = 'attachment'
HERE;
			$images = $wpdb->get_results($sql);

			$upload_info = wp_upload_dir();
			foreach( $images as $idx => $image ){
				$image_path = str_replace($upload_info['baseurl'], $upload_info['basedir'], $image->guid);
				$this->delete_files( $image_path );
				$this->create_files( $image_path );
			}
		}
		
		return $settings;
	}

	/* 設定画面 */
	function plugin_options() {
		?>
		<div class="wrap">
			<div class="icon32" id="icon-options-general"><br /></div>
			<h2><?php echo __( 'SettingsPageTitle', 'auto-image-resize' ) ?></h2>
			
			<form action="options.php" method="post">

				<?php settings_fields('airesize_settings'); ?>

				<h3><?php echo __( 'SettingTitleMaxWidth', 'auto-image-resize' ) ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="airesize-mobilewidth"><?php echo __( 'SmartPhone', 'auto-image-resize' ) ?>(px)</label></th>
						<td><input type="number" id="airesize-mobilewidth" name="airesize_settings[mobile_width]"  value="<?php echo esc_attr( $this->settings['mobile_width'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="airesize-tabletwidth"><?php echo __( 'Tablet', 'auto-image-resize' ) ?>(px)</label></th>
						<td><input type="number" id="airesize-tabletwidth" name="airesize_settings[tablet_width]"  value="<?php echo esc_attr( $this->settings['tablet_width'] ); ?>" class="small-text" /></td>
					</tr>
				</table>
				
				<h3><?php echo __( 'SettingTitleSaveMode', 'auto-image-resize' ) ?></h3>
				<table class="form-table">
					<tr>
						<td>
						<label for="airesize-imageinit">
							<input type="checkbox" name="airesize_settings[image_init]" id="airesize-imageinit" /> <?php echo __( 'SettingSubTitleAllChange', 'auto-image-resize' ) ?>
						</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" value="<?php echo __( 'Save Changes') ?>" class="button-primary" id="airesize-submit" name="airesize-submit">
				</p>

			</form>
			<div id="airesize-progress"></div>

			<p class="description"><?php echo __( 'WarningText', 'auto-image-resize' ) ?></p>
			
		</div>
		<?php
	}
	
	/*
	function header_first(){
		$jQueryUrl = 'http://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js';
		wp_enqueue_script('jquery', $jQueryUrl, false, '1.7.1');
		
	}
	
	function header_ready(){
		echo <<<EOF
		<script type="text/javascript">
		jQuery(document).ready(function($){
			
		});
		</script>
EOF;
	}
	 */

}

new AIResize();


