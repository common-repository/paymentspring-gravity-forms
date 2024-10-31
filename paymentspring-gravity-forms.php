<?php
/**
 * Plugin Name: PaymentSpring for Gravity Forms
 * Plugin URI: https://www.paymentspring.com/docs/integrations/wordpress
 * Description: Integrates Gravity Forms and PaymentSpring.
 * Version: 2.2.9
 * Author: PaymentSpring
 * Author URI: https://www.paymentspring.com/
 * License: GPL2
 *
 * ----------------------------------------------------------------------------
 * Copyright 2017 PaymentSpring
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as 
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


// Don't allow this class to be accessed directly
defined( 'ABSPATH' ) or die();

// Autoload composer files
require("vendor/autoload.php");

// Set constants for Gravity Forms to reference this file elsewhere in the plugin
$payment_spring_gf_file = __FILE__;
define('PAYMENT_SPRING_GF_FILE', $payment_spring_gf_file);
define('PAYMENT_SPRING_GF_PATH', WP_PLUGIN_DIR . '/' . basename(dirname($payment_spring_gf_file)));

// Load Plugin
require_once("includes/class-payment-spring-gravity-forms.php");

// Start Plugin

new PaymentSpringGravityForms();
