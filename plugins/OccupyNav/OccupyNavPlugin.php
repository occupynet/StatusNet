<?php
class OccupyNavPlugin extends Plugin {
  function onStartShowBody($action) {
    $action->script('https://nav.occupy.net/occupynet_nav.js');
    return true;
  }
}
