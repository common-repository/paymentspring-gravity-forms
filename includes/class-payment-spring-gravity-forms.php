<?php
  // Load all of our files for the plugin
  require_once("strings.php");

  require_once("class-payment-spring-view-helpers.php");
  require_once("class-payment-spring-form-handler.php");
  require_once("class-payment-spring-settings.php");

  require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

  class PaymentSpringGravityForms{

    public static $twigEngine;
    public $settingsPage, $formHandler;

    // Use Twig for handling templates 
    public static function loadTwigEngine(){
      $loader = new Twig_Loader_Filesystem(PAYMENT_SPRING_GF_PATH . '/includes/templates/');
      self::$twigEngine = new Twig_Environment($loader);
      PaymentSpringGravityForms::addTwigFunctions();
    } 

    public function __construct(){
      add_action( 'init', array( $this, 'init' ) );
      add_action( 'admin_init', array( $this, 'admin_init' ) );
      register_activation_hook(PAYMENT_SPRING_GF_FILE, array( $this, "activate" ) );

      if ( class_exists( "GFForms" ) && class_exists( "RGForms" ) ) {
        // Initialize our PaymentSpring class to use our set API keys
        \PaymentSpring\PaymentSpring::setApiKeys(
          $this->get_api_key("public"),
          $this->get_api_key("private")
        );

        // Admin section sttings
        $this->settingsPage = new PaymentSpringSettings();
        // User side of functions
        $this->formHandler = new PaymentSpringFormHandler();
      }
    }

    public function init(){
      // Make sure Gravity Forms is installed before initializing the plugin
      if($this->hasRequiredPlugins()){
        load_plugin_textdomain( "gf_paymentspring" ); 
        PaymentSpringGravityForms::loadTwigEngine();
        $this->formHandler->linkWithGF();
      }
    }

    public function admin_init(){
      if($this->hasRequiredPlugins()){
        $this->settingsPage->setupSettingsPage();
        $this->settingsPage->linkWithGF();
      }
    }

    public function activate(){
      // Make sure Gravity Forms is installed before activating the plugin
      if(!$this->hasRequiredPlugins()){
        wp_die( __( "Please install and activate Gravity Forms first.", "gf_paymentspring" ) );
      }
    }

    public static function get_api_key ($key_type = "public", $mode = null) {
      $options = get_option('gf_paymentspring_account');
      if(!$mode){
        $mode = $options["mode"];
      }
      return $options["$mode" . "_" . $key_type . "_key"];
    } 

    // Fields to display in the Credit Card "General" tab
    public static function paymentSpringFields(){
      return array(
        "amount" => "Amount",
        "quantity" => "Quantity",
        "first_name" => "First Name",
        "last_name" => "Last Name",
        "address_1" => "Address 1",
        "address_2" => "Address 2",
        "city" => "City",
        "state" => "State",
        "zip" => "Zip",
        "phone" => "Phone",
        "fax" => "Fax",
        "website" => "Website",
        "company" => "Company",
        "email_address" => "Email",
        "plan_subscription" => "Plan",
        "subscription_amount_override" => "Subscription Amount (Override Default)",
        "single_charge" => "Single Charge",
        "customer" => "Customer",
      );
    }

    /*
      Private Functions
    */

    private function hasRequiredPlugins(){
      if ( is_admin() ) {
        if ( !class_exists( "GFForms" ) || !class_exists( "RGForms" ) ) {
          deactivate_plugins( plugin_basename(PAYMENT_SPRING_GF_FILE) );
          return false;
        }  
      }
      return true;
    }

    // Twig, out of the box, doesn't handle all PHP functions. There are only a couple we need to add
    private static function addTwigFunctions(){
      // Allow it to use Wordpress' built in I18n
      $i18n = new Twig_SimpleFunction('_e', function ($key, $domain="gf_paymentspring") {
        _e($key, $domain);
      });
      self::$twigEngine->addFunction($i18n);

      // GF settings fields
      $settings_fields = new Twig_SimpleFunction('settings_fields', function ($domain) {
        settings_fields($domain);
      });
      self::$twigEngine->addFunction($settings_fields);

      // GF tooltips
      $settings_fields = new Twig_SimpleFunction('gform_tooltip', function ($domain) {
        return gform_tooltip($domain);
      });
      self::$twigEngine->addFunction($settings_fields);
    }

  }
