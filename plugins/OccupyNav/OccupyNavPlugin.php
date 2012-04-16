<?php
class OccupyNavPlugin extends Plugin {
  function onStartShowHeader($action) {
    $action->script('plugins/OccupyNav/Navigation/occupynet_nav.js');
    return true;
  }
}
