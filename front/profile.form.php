<?php
Session::checkRight("profile", UPDATE);

$prof = new PluginGestionProfile();

//Save profile
if (isset ($_POST['update'])) {
   $prof->update($_POST);
   Html::back();
}

?>
