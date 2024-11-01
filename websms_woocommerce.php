<?php
/*
Plugin Name: WEBSMS.RU WooCommerce
Description: SMS уведомления о событиях WooCommerce через шлюз WEBSMS.RU
Version: 1.01
Author: WEBSMS.RU
Author URI: http://websms.ru
Plugin URI: http://websms.ru/services/sending/request#
*/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

if (!is_callable('is_plugin_active')) 
{
	require_once str_replace(array(site_url(),'\\','//'), ARRAY(ABSPATH,'/','/'), admin_url()).'includes/plugin.php'; 
}

if (!class_exists('Http_Websms')) 
{
  require_once(plugin_dir_path( __FILE__ ).'httpwebsms.php');
}

if (is_plugin_active('woocommerce/woocommerce.php')) 
{
	add_action('plugins_loaded', 'websms_woocommerce', 0);
}

function websms_woocommerce() 
{
	return new websms_woocommerce();
}

function websms_error_log($log) 
{
  if (is_array($log) || is_object($log)) {
     error_log(print_r($log,true));
  } else {
     error_log($log);
  }
}

class websms_woocommerce { 
  private $net;
  private $keys;
  
	public function __construct() 
	{
		$this->keys = array('websms_login','websms_password','websms_seller_phone','websms_event_pending','websms_event_processing','websms_event_on-hold','websms_event_completed','websms_event_cancelled','websms_event_refunded','websms_event_failed');
		add_action('admin_menu',array($this,'admin_menu'));
		// add_action('woocommerce_new_order', array($this,'process_new_order'),1,1);
		add_action('woocommerce_order_status_changed', array($this,'process_event'),1,3);
		register_deactivation_hook(__FILE__, array($this, 'deactivation'));
		$this->net = new Http_Websms(get_option('websms_login'),get_option('websms_password'));
	}

  /**
  *   отправка СМС-сообщения через шлюз websms.ru
  **/
  function send_sms($telNum,$messText)
  {
    if ( !$telNum || !$messText ){ 
      return false; 
    }

    try {
       $ret = $this->net->sendSms($telNum, $messText);
       if (isset($ret['error_code'])) {
         if ($ret['error_code']==0){
            return $ret['message_id'];
         } else {
            return $ret['error_mess'];
         }
       }
       return false;
    } catch (Exception $ex) {
       return $ex->getMessage();
    }
  }
	 
	public function deactivation() 
	{
    foreach($this->keys as $k) { delete_option($k); }
	}
	
	public function admin_menu() 
	{
		add_submenu_page('woocommerce','SMS уведомления о событиях WooCommerce через шлюз WEBSMS.RU', 'WEBSMS.RU', 'manage_woocommerce', 'websms_settings', array(&$this,'options'));
	}
	
  /**
  *   настройка плагина
  **/
	public function options() 
	{
      /*  сохраняем параметры, если была команда Сохранить  */
      foreach($this->keys as $k) { 
        if (strripos($k, 'event') == false){ 
          if (isset($_POST[$k])) {
            $text_option = substr(esc_attr(sanitize_text_field($_POST[$k])),0,50); 
            update_option($k,$text_option); 
          }  
        } else {
          if (isset($_POST[$k.'_dest']) && isset($_POST[$k.'_message'])) {
            $destOpt = $this->sanitize_dest_key($_POST[$k.'_dest']);
            $messTextOpt = substr(trim(esc_attr(sanitize_textarea_field($_POST[$k.'_message']))),0,1570);
            $eventOpt = array('dest'=>$destOpt, 'message'=>$messTextOpt); 
            update_option($k,$eventOpt);
          }  
        }  
      } 
      /* отправляем тестовое сообщение, если нужно, и возвращаемся на страницу с результатом  */
			if (isset($_POST['websms_login']) && isset($_POST['websms_password'])) {
        $this->net->setLogin(get_option('websms_login',''));
        $this->net->setPassword(get_option('websms_password',''));
        if (isset($_POST['test']) && get_option('websms_seller_phone')) {
          $rez = $this->send_sms(get_option('websms_seller_phone',''),'Test message from WEBSMS.RU plugin for WooCommerce');
          if (is_numeric($rez)) {
            wp_redirect(admin_url('admin.php?page=websms_settings&test_success=1'));
          } else {
            wp_redirect(admin_url('admin.php?page=websms_settings&test_error='.$rez));
          } 
        } else {
          wp_redirect(admin_url('admin.php?page=websms_settings&saved=1'));
        }
      }      
      ?>
		<div class="wrap woocommerce">
			<form method="post" id="mainform" action="<?= admin_url('admin.php?page=websms_settings') ?>">
				<h2>SMS оповещения о событиях WooCommerce через шлюз WEBSMS.RU - Настройка плагина</h2>
				<table><tr><td style="vertical-align:middle"><a href="http://cab.websms.ru" target="_blank"><img src="<?=plugin_dir_url(__FILE__).'images/logo_websms.png'?>"/></a></td><td style="width:20px;"></td><td style="vertical-align:middle"><a href="http://cab.websms.ru" target="_blank">Личный кабинет на WEBSMS.RU</a></td></tr></table>
				<table class="form-table">
					<tr><th>Логин пользователя websms</th><td colspan="2"><input title="Имя входа в личный кабинет WEBSMS.RU" required type="text" name="websms_login" id="websms_login" value="<?=esc_attr(get_option('websms_login','')) ?>" size="50" maxlength="50"/></td><tr/>
					<tr><th>Пароль http</td><td colspan="2"><input title="Пароль для работы с http api сервиса WEBSMS.RU" required type="text" name="websms_password" id="websms_password" value="<?=esc_attr(get_option('websms_password','')) ?>" size="50" maxlength="50"/></td><tr/>
					<tr><th>Телефон владельца магазина</td><td colspan="2"><input required type="text" name="websms_seller_phone" id="websms_seller_phone" value="<?=$this->net->checkPhone(get_option('websms_seller_phone',''))  ?>" size="50" maxlength="20" title="На этот номер будут приходить сообщения владельцу магазина/продавцу"/>&nbsp;<small>Например, 79012345678</small></td></tr>
					<tr><th colspan="3">Выберите события, на которые должны отправляться оповещения покупателям и продавцу:</td></tr>
          <?php foreach(wc_get_order_statuses() as $stat => $descr) {  
            $stat  = str_replace('wc-','',$stat);
            $descr = str_replace('В ожидании оплаты','Новый заказ',$descr);

            $defOptionArr = array('dest'=>'disabled','message'=>'Статус заказа изменился на {STAT_NEW}. № заказа {ORDER}'); 
            if ($stat=='pending') {
              $defOptionArr = array('dest'=>'disabled','message'=>'Ваш заказ на сумму {TOTAL} принят. № заказа {ORDER}'); 
            } else if ($stat=='completed') {
              $defOptionArr = array('dest'=>'disabled','message'=>'Заказ № {ORDER} выполнен. Спасибо, что воспользовались нашими услугами!'); 
            } else if ($stat=='cancelled') {
              $defOptionArr = array('dest'=>'disabled','message'=>'Заказ № {ORDER} отменен. Сожалеем и надеемся снова увидеть Вас в нашем магазине'); 
            }  

            $curOptionArr = get_option('websms_event_'.$stat, $defOptionArr); 
            ?>
            <tr>
            <th style="width:110px"><?= $descr ?></th>
            <td style="width:140px">
            <input type="radio" value="disabled" name="<?= 'websms_event_'.$stat.'_dest' ?>" <?= ($curOptionArr['dest']=='disabled' ? ' checked="checked"' : '') ?> />Не&nbsp;отправлять<br/>
            <input type="radio" value="seller"   name="<?= 'websms_event_'.$stat.'_dest' ?>" <?= ($curOptionArr['dest']=='seller' ? ' checked="checked"' : '') ?> />Продавцу<br/>
            <input type="radio" value="customer" name="<?= 'websms_event_'.$stat.'_dest' ?>" <?= ($curOptionArr['dest']=='customer' ? ' checked="checked"' : '') ?> />Покупателю<br/>
            <input type="radio" value="both"     name="<?= 'websms_event_'.$stat.'_dest' ?>" <?= ($curOptionArr['dest']=='both' ? ' checked="checked"' : '') ?> />Обоим
            </td>
            <td><textarea name="<?= 'websms_event_'.$stat.'_message' ?>" rows="3" cols="60" maxlength="1570" title="Этот текст будет отправлен в СМС-сообщении в ответ на событие <?= $descr ?>"><?= esc_textarea($curOptionArr['message']) ?></textarea></td>
            </tr>
          <?php } ?>
					<tr><td colspan="3">
					  <div>Переменные, которые можно включить в текст сообщения:</div>
            <div style="background:lightcyan; padding:2px 2px 4px 4px;">{ORDER} - номер заказа, {TOTAL} - сумма заказа, {EMAIL} - эл.почта покупателя, {FIRSTNAME} - имя покупателя, {LASTNAME} - фамилия покупателя, {PHONE} - телефон покупателя, {CITY} - город доставки, {ADDRESS} - адрес доставки, {SHOPNAME} - название магазина, {STAT_PREV} - старый статус, {STAT_NEW} - новый статус, {ITEM_LIST} - список заказанных товаров</div>
          </td>
          </tr>
				</table>
				<br/>
				<div style="color: #ffa20f">
				<input type="submit" class="button-primary" style="color:#FFFFFF; background-color:#2f78b8;" name="save" value="Сохранить"/> 
				<?php
				if ((isset($_GET['saved'])) && ($_GET['saved'] == '1')){
          echo '&nbsp;Данные сохранены';
				}
				?> 
				</div>
				<small>Сохранить произведенные изменения параметров</small><br/>				
				<div style="color:#2f78b8; margin-top:4px;">
				<input type="submit" class="button-secondary" style="color: #ffa20f" name="test" value="Тестовое сообщение"/>&nbsp; 
				<?php
				if ((isset($_GET['test_success'])) && ($_GET['test_success'] == '1')){
          echo '&nbsp;Тестовое сообщение было успешно отправлено';
				}
				if (isset($_GET['test_error'])){
          echo '&nbsp;<b style="color: red">Ошибка отправки тестового сообщения: '.esc_html(wp_kses_post($_GET['test_error']))."</b>";
				}
				?> 
				</div>
				<small>Параметры будут сохранены, после чего будет отправлено тестовое сообщение на телефон продавца, если он введен</small><br/>
			</form>
		</div>
<?php
	}

  /**
  *   обработка наступившего события появления нового заказа
  **/
	public function process_new_order($order_id)
	{
    $this->process_event($order_id,'','pending');
	}

  /**
  *   обработка наступившего события смены статуса заказа
  **/
	public function process_event($order_id,$stat_prev,$stat_new)
	{
		if ((get_option('websms_login'))&&(get_option('websms_password'))) {
			$order = new WC_Order($order_id);
			$item_list = $order->get_items(array('line_item'));
			$items = '';
			foreach( $item_list as $it ) {
				if ( ($prod = $order->get_product_from_item($it)) && ($sku = $prod->get_sku()) ) {
					$it['name'] = $sku.' '.$it['name'];
				}
				$items .= "\n".$it['name'].': '.$it['qty'].'x'.$order->get_item_total($it).'='.$order->get_line_total($it);
			}
			$sh = $order->get_shipping_methods();
			foreach( $sh as $it) {
				$items .= "\n".__('Shipping','woocommerce').': '.$it['name'].'='.$it['cost'];
			}
			$items .= "\n";
			
			$searcArr = array('{ORDER}', '{TOTAL}', '{EMAIL}', '{PHONE}', '{FIRSTNAME}', '{LASTNAME}', '{CITY}', '{ADDRESS}', '{SHOPNAME}', '{STAT_PREV}', '{STAT_NEW}', '{ITEM_LIST}');
			$replArr = array(
				html_entity_decode($order->get_order_number()),
				str_replace(array('<span class="woocommerce-Price-amount amount">','<span class="woocommerce-Price-currencySymbol">','</span>','<bdi>','</bdi>','₽'),array('','','','','','руб.'),html_entity_decode($order->get_formatted_order_total(false,false))),
				html_entity_decode($order->get_billing_email()),
				html_entity_decode($order->get_billing_phone()),
				html_entity_decode($order->get_billing_first_name()),
				html_entity_decode($order->get_billing_last_name()),
				html_entity_decode($order->get_shipping_city()),
				html_entity_decode($order->get_shipping_address_1().($order->get_shipping_address_2() ? ' '.$order->get_shipping_address_2() : '')),
				html_entity_decode(get_option('blogname')),
				html_entity_decode(($stat_prev !== ''? wc_get_order_status_name($stat_prev):'')),
				html_entity_decode(($stat_new !== '' ? wc_get_order_status_name($stat_new):'')),
				html_entity_decode(strip_tags($items)) 
			);
      
      $eventOpt = get_option('websms_event_'.$stat_new);
      //websms_error_log('websms_event_'.$stat_new);
      //websms_error_log($eventOpt);
      if (isset($eventOpt)) {
        if (isset($eventOpt['dest'])) {
          // создание оповещений Продавцу
          if (($eventOpt['dest']=='seller')||($eventOpt['dest']=='both')) {
            $sellerPhone = get_option('websms_seller_phone','');       
            if ($sellerPhone != ''){
              $messText = str_replace($searcArr,$replArr,$eventOpt['message']);
              //websms_error_log('Phone='.$sellerPhone.';  Text='.$messText);
              $this->send_sms($sellerPhone,$messText);
            }
          }        
          // создание оповещений Покупателю
          if (($eventOpt['dest']=='customer')||($eventOpt['dest']=='both')) {
              $messText = str_replace($searcArr,$replArr,$eventOpt['message']);
              $customerPhone = $order->get_billing_phone();
              //websms_error_log('Phone='.$customerPhone.';  Text='.$messText);
              $this->send_sms($customerPhone,$messText);
          }      
        }
      }
		}
	}
	
	function sanitize_dest_key($key)
  {
    if (($key=='disabled')||($key=='seller')||($key=='customer')||($key=='both')){
      return $key;
    } else {
      return 'disabled';
    }
  }	
}
?>