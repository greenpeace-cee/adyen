<?php

class CRM_Adyen_Page_Notification extends CRM_Core_Page {

  public function run() {
    // extract posted JSON and convert to array
    $request = json_decode(file_get_contents('php://input'), TRUE);
    $notifications = Civi\Adyen\Notification\Factory::createFromPayload($request);
    foreach ($notifications as $notification) {
      $notification->store();
    }
    echo '[accepted]';
    CRM_Utils_System::civiExit();
  }

}
