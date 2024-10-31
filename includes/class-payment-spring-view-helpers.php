<?php
  class PSViewHelpers{
    public static function adminLinkTo($path, $name){
      return PaymentSpringGravityForms::$twigEngine->render(
        "layouts/link.twig", 
        array("link_to" => self_admin_url($path), "name" => $name)
      );
    }
  }
