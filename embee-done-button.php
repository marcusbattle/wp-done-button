<?php

/**
 * Plugin Name: Done Button
 * Plugin URI: http://marcusbattle.com/plugins/post-read
 * Description: Confirms that a user has read the post by giving them the infamous "Done" Button. 
 * Version: 0.1.0
 * Author: Marcus Battle
 * Author URI: http://marcusbattle.com
 * License: A "Slug" license name e.g. GPL2
 * Tags: post, read, confirmation, done, reading
 */



/*
*/
function done_button_scripts() {

	wp_register_script( 'done-button', plugins_url( '/assets/js/embee.donebutton.js', __FILE__ ), array('jquery'), '', true );
	wp_enqueue_script( 'done-button' );

	wp_register_style( 'font-awesome', '//maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css' );
	wp_register_style( 'done-button', plugins_url( '/assets/css/embee.donebutton.css', __FILE__ ), array('font-awesome') );
	
	wp_enqueue_style( 'font-awesome' );
	wp_enqueue_style( 'done-button' );

	wp_localize_script( 'done-button', 'done_button', array( 
		'ajaxurl' => admin_url('admin-ajax.php'),
		'is_user_logged_in' => is_user_logged_in()
	) );

}

add_action( 'wp_enqueue_scripts', 'done_button_scripts' );


/* Allow subscribers to see private posts */
function done_button_allow_private_posts() {
	
	global $wp_roles;
	
	$wp_roles->add_cap('subscriber', 'read_private_posts');

}

add_action( 'init', 'done_button_allow_private_posts' );


/* redirect users to front page after login */
function done_button_redirect_to_front_page() {
	
	global $redirect_to;

	if ( !isset($_GET['redirect_to']) ) {

		$redirect_to = get_option('siteurl');

	}

}

add_action('login_form', 'done_button_redirect_to_front_page');


/*
*/
function done_button_login() {
    
    if ( $device_session_id = done_button_get_device_session_id() ) {

    	setcookie( "device_session_id_global", $device_session_id, time() + (10 * 365 * 24 * 60 * 60), home_url( '/', 'relative' ) );

    }

    // Create a new device_session_id	
	done_button_set_device_session_id();

}

add_action( 'wp_login', 'done_button_login' );

function done_button_logout() {

	$device_session_id_global = isset($_COOKIE['device_session_id_global']) ? $_COOKIE['device_session_id_global'] : '';

	// Create a new device_session_id	
	done_button_set_device_session_id( $device_session_id_global );

	if ( $device_session_id_global ) {
		setcookie( "device_session_id_global", '', time() - 3600, home_url( '/', 'relative' ) );
	}

}

add_action( 'wp_logout', 'done_button_logout' );

/*
*/
function done_button_filter_content( $content ) {

	global $post;

	$approved_post_types = array('post','page');

	if ( !in_array( get_post_type(), $approved_post_types ) )
		return $content;

	if ( $done_button = done_button_is_pressed( $post->ID ) ) {

		$post_read_date = human_time_diff( strtotime($done_button->date_created), current_time('timestamp') ) . ' ago';
		$content .= "<p><a class=\"done-button disabled\" data-post-id=\"$post->ID\" disabled=\"true\"><i class=\"fa fa-check-circle-o fa-lg\"></i> <span>Read $post_read_date</span></a></p>";

	} else {
	
		$content .= "<a class=\"done-button\" data-post-id=\"$post->ID\"><i class=\"fa fa-thumbs-o-up fa-lg\"></i> <span>Done</span></a>";

	}

	return $content;

}

add_filter( 'the_content', 'done_button_filter_content' );


/*
*/
function done_button_ajax_add_press() {
	
	global $wpdb;

	// Check to see if there's a record for the post
	$done_button = done_button_is_pressed( $_POST['post_id'] );

	if( $done_button ) {

		echo json_encode(array(
			'success' => false,
			'message' => "You've read this post."
		));

		exit;
	}

	// If all checks pass, record that the post has been read
	$wpdb->insert( 
		$wpdb->prefix . "done_buttons", 
		array( 
			'post_id' => $_POST['post_id'],
			'user_id' => get_current_user_id(),
			'device_session_id' => (done_button_get_device_session_id()) ? done_button_get_device_session_id() : done_button_set_device_session_id(),
			'ip_address' => $_SERVER['REMOTE_ADDR'],
			'date_created' => current_time('mysql')
		),
		array(
			'%d',
			'%d',
			'%s',
			'%s',
			'%s'
		)
	);

	if ( $wpdb->insert_id ) {

		echo json_encode(array(
			'success' => true,
			'post_id' => $_POST['post_id'],
			'button_label' => "Read " . human_time_diff( current_time('timestamp'), current_time('timestamp') ) . ' ago'
		));

	}

	exit;

}

add_action( 'wp_ajax_nopriv_add_done_button', 'done_button_ajax_add_press' );
add_action( 'wp_ajax_add_done_button', 'done_button_ajax_add_press' );


/*
*/
function done_button_is_pressed( $post_id ) {

	global $wpdb;

	// Check to see if the logged in user has read this post
	if ( is_user_logged_in() ) {

		$user_id = get_current_user_id();
		$device_session_id = done_button_get_device_session_id();

		$table_name = $wpdb->prefix . "done_buttons";
		$done_button_id = $wpdb->get_row("SELECT * FROM $table_name WHERE user_id = $user_id AND post_id = $post_id");

		return $done_button_id;

	} 

	// Check to see if this visitor has read this post
	if ( $device_session_id = done_button_get_device_session_id() ) {

		$table_name = $wpdb->prefix . "done_buttons";
		$done_button_id = $wpdb->get_row("SELECT * FROM $table_name WHERE device_session_id = '$device_session_id' AND post_id = $post_id");

		return $done_button_id;

	}

	return false;

}


/*
*/
function done_button_get_device_session_id() {

	if ( isset($_COOKIE['device_session_id']) ) 
		return $_COOKIE['device_session_id'];

	return false;

}


/*
*/
function done_button_set_device_session_id( $session_id = '' ) {

	$device_session_id = ($session_id) ? $session_id : md5( current_time('mysql') . $_SERVER['REMOTE_ADDR'] );

	setcookie( "device_session_id", $device_session_id, time() + (10 * 365 * 24 * 60 * 60), home_url( '/', 'relative' ) );

	return $device_session_id;

}

function done_button_install() {
	
	global $wpdb;

	$table_name = $wpdb->prefix . "done_buttons";
      
	$sql = "CREATE TABLE $table_name (
	done_button_id mediumint(9) NOT NULL AUTO_INCREMENT,
	post_id mediumint(9) NOT NULL,
	user_id mediumint(9) NOT NULL,
	device_session_id VARCHAR(32) NOT NULL,
	ip_address VARCHAR(45) NOT NULL,
	date_created DATETIME NOT NULL,
	UNIQUE KEY done_button_id (done_button_id)
	);";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

}

register_activation_hook( __FILE__, 'done_button_install' );

