<?php
  class PaymentSpringSettings{

    public $plans;
    public $templates;

    public function __construct(){
      $this->plans = \PaymentSpring\Plan::listPlans(); 

      $response = \PaymentSpring\PaymentSpring::makeRequest("receipts/templates");
      $this->templates = json_decode($response);
    } 

    public function setupSettingsPage(){
      RGForms::add_settings_page("PaymentSpring", array($this, "SettingsPageTemplate"), "");
    }

    # FIXME: DELETE!
    public function console_log( $data ){
      echo '<script>console.log('. json_encode( $data ) .')</script>';
    }

    public function settingsPageTemplate(){
      $options = get_option('gf_paymentspring_account');
      echo PaymentSpringGravityForms::$twigEngine->render("settings_page.twig", array(
        "options" => $options,
        "available_plans" => $this->plans,
        "available_templates" => $this->templates,
        "show_plans" => isset($this->plans->list),
        "allow_one_time_charges" => isset($options['allow_one_time_charges']) ? $options['allow_one_time_charges'] : "false",
        "create_customer_on_one_time_purchase" => isset($options['create_customer_on_one_time_purchase']) ? $options['create_customer_on_one_time_purchase'] : "false",
        "customer_only" => isset($options['customer_only']) ? $options['customer_only'] : "false",
        "send_receipts" => isset($options['send_receipts']) ? $options['send_receipts'] : "false",
        "customers_can_override_plan_amount" => isset($options['customers_can_override_plan_amount']) ? $options['customers_can_override_plan_amount'] : "false",
        "receipt_template_id" => $options['receipt_template_id']   
      ));
    }

    public function linkWithGF(){
      register_setting( "gf_paymentspring_account_options", "gf_paymentspring_account", array( $this, "validate_settings" ) );

      add_action( "gform_entry_info", array( $this, "account_mode_entry_info" ), 10, 2);
      add_action( "gform_field_standard_settings", array( $this, "field_settings_checkbox" ), 10, 2 );
      add_action( "gform_editor_js", array( $this, "field_settings_js" ) );

      add_filter( "plugin_action_links_" . plugin_basename( PAYMENT_SPRING_GF_FILE ), array($this, "add_plugin_action_links" ) );
      add_filter( "gform_enable_credit_card_field", "__return_true" );
      add_filter( 'gform_predefined_choices', array($this, 'addPlansToPredefinedChoices') );
      add_filter( "gform_tooltips", array( $this, "add_tooltips" ) );
    }
  
    // Plans are enabled on the GF PaymentSpring settings page
    public function getEnabledPlans(){
      $options = get_option('gf_paymentspring_account');
      $enabledPlanOptions = array();
      foreach($options as $optionKey => $optionValue){
        if($optionValue == "enabled"){
          $enabledPlanOptions[] = $optionKey;
        }
      } 

      $this->console_log($options);

      $plans = array();
      foreach($this->plans->list as $plan){
        if(in_array("plan_$plan->id", $enabledPlanOptions)){
          $plans[] = $plan;
        }
      }
      return $plans;
    }

    // Add PaymentSpring plans to "Predefined Choices" in dropdown choices. 
    public function addPlansToPredefinedChoices( $choices ) {
      $plans = $this->getEnabledPlans();
      echo 'console.log('. json_encode( $plans ) .')';
      if(sizeof($plans) > 0){
        $enabledPlans = array();
        foreach($plans as $eachPlan){ 
          $price = floatval($eachPlan->amount) / 100;
          $enabledPlans[] = "$eachPlan->name ($$price/$eachPlan->frequency)|$eachPlan->name($eachPlan->id)|:$price";
        }
        return array_merge(array("PaymentSpring Plans" => $enabledPlans), $choices);
      }else{
        return $choices;
      }
    }

    // Allow our settings to be saved 
    public function validate_settings($input){
      $default_settings = array(
        "mode" => $input["mode"] == "live" ? "live" : "test",
        "test_private_key" => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["test_private_key"] ),
        "test_public_key"  => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["test_public_key"]  ),
        "live_private_key" => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["live_private_key"] ),
        "live_public_key"  => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["live_public_key"]  ),
        "allow_one_time_charges" => $input["allow_one_time_charges"],
        "create_customer_on_one_time_purchase" => $input["create_customer_on_one_time_purchase"],
        "customer_only" => $input["customer_only"],
        "send_receipts" => $input["send_receipts"],
        "customers_can_override_plan_amount" => $input["customers_can_override_plan_amount"],
        "receipt_template_id"  => preg_replace( "/[^0-9_]/", "", $input["receipt_template_id"]  ),
      );

      $enabled_plans = array();
      if(isset($this->plans->list)){ 
        foreach($this->plans->list as $eachPlan){
          $enabled_plans["plan_$eachPlan->id"] = $input["plan_$eachPlan->id"];
        }
      }

      return array_merge($default_settings, $enabled_plans);
    }

    public function account_mode_entry_info($entry_meta, $form_id){
    }

    public function field_settings_checkbox($position, $form_id){
      // right below Field Label
      if ( $position == 25 ) {
        echo PaymentSpringGravityForms::$twigEngine->render("settings_page/field_settings_checkbox.twig", array(
          "payment_fields" => PaymentSpringGravityForms::paymentSpringFields(),
        ));
      }
    }

    // Add our JS template for the settings
    public function field_settings_js(){
      echo PaymentSpringGravityForms::$twigEngine->render("settings_page/field_settings_js.twig", array(
        "payment_fields" => PaymentSpringGravityForms::paymentSpringFields(),
      ));
    }

    // Add link to settings page on plugins page 
    public function add_plugin_action_links($links ) {
      return array_merge (
        array(PSViewHelpers::adminLinkTo("admin.php?page=gf_settings&subview=PaymentSpring2", "Settings")),
        $links
      );
    }

    public function add_tooltips($tooltips){
      $tooltips["gf_paymentspring_api_mode"] = "<h6>" . __( "API Mode" ) . "</h6>" . __( "Select 'Test' mode to run charges in the PaymentSpring test environment. Switch to 'Live' mode when you want to run charges for real." );

      $tooltips["gf_paymentspring_test_private_key"] = "<h6>" . __( "Test Private Key" ) . "</h6>" . __( "Enter your test mode private key." );
      $tooltips["gf_paymentspring_test_public_key"]  = "<h6>" . __( "Test Public Key" )  . "</h6>" . __( "Enter your test mode public key." );
      $tooltips["gf_paymentspring_live_private_key"] = "<h6>" . __( "Live Private Key" ) . "</h6>" . __( "Enter your live mode private key." );
      $tooltips["gf_paymentspring_live_public_key"]  = "<h6>" . __( "Live Public Key" )  . "</h6>" . __( "Enter your live mode public key." );
      $tooltips["gf_paymentspring_use_card_checkbox"]  = "<h6>" . __( "Use with PaymentSpring?" )  . "</h6>" . __( "Check this box if you want to use PaymentSpring to process transactions using card information from this Credit Card field." );

      $tooltips["gf_paymentspring_amount_field"]  = "<h6>" . __( "PaymentSpring Amount Field" )  . "</h6>" . __( "Select the field containing the amount to charge to the card information entered into this field. If new fields are added to this form the form will have to be saved before they appear here." );
      return $tooltips;
    }
  }
