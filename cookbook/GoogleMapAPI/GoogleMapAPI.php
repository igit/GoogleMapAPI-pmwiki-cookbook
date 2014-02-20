<?php
/*

TODO: Add on Google Geocoding:
NOTE: http://www.google.com/apis/maps/documentation/#Geocoding_HTTP_Request

# Google Map API for PmWiki 
# =========================
# 
# Copyright Statement 
# -------------------
# 
# Copyright (c) 2006--2007, Benjamin C. Wilson. All Rights Reserved. 
# 
# Read the documentation.txt accompanying this software package for licensing
# and installation and usage instructions.
#
*/
define('GMAPATH', dirname(__FILE__) . '/');
include_once(GMAPATH.'library/php-local-browscap.php');

# SDV()
SDV($GmaDebug, 0);
SDV($GmaForceWebsafe, 0); # Toggles site-wide websafe enforcement.
SDVA($GmaDefaults, array(
    'ctrl' => 'large,overview,type',
    'view' => 'normal',
    'height' => '300px',
    'width' => '500px',
    'fromto' => 'y',
    'float' => '',
    'color' => '#808',
    'thickness' => 2,
    'opacity' => 0.7,
    'zoom' => 0
    )
);
SDV($GmaKey, '');
# Internals.
$GmaDebugMsg = array();
$GmaHTML = '';
$GmaIEFix = '';
$GmaMaps = array();
$GmaLines = array();
$GmaPoints = array();
$GmaPointPopulation = 0;
$GmaVersion = '2.2.0-Pre9 (March 8, 2010)';
SDV($RecipeInfo['GoogleMapAPI']['Version'], $GmaVersion);

if (is_array($GmaKey)) 
    $GmaKey = $GmaKey[preg_replace('/www./','', $_SERVER['HTTP_HOST'])];

SDV($GmaScheme, 'http');
if (!empty($_SERVER['HTTPS'])) {
   $GmaScheme = 'https';
}


# Markup
# * (:gma-map [options]:)
# * (:gma-point lat lon [options]:)
# * (:gma-line lat lon [options]:)
Markup('gma', '>if', '/\(:gma-(\S+)\s?(.*?):\)/ie',"gmaMarkup('$1','$2');");
class GmaLine
{
  function GmaLine($id) {
    global $GmaLines, $GmaDefaults;
    $this->id = $id;
    $this->color = $GmaDefaults['color'];
    $this->opacity = $GmaDefaults['opacity'];
    $this->points = array();
    $this->thickness = $GmaDefaults['thickness'];
  }
  function addPoint($lat, $lon) {
    if ($lat && $lon) array_push($this->points, "new GLatLng($lat, $lon)");
  }
  function line($map_id='map') {
    $id = $this->id;
    $pad = str_pad('', 26 + strlen($id), ' ', STR_PAD_LEFT);
    $points = '['.join(",\n$pad", $this->points).']';
    $opacity = ($this->opacity > 1) 
        ? sprintf("%0.2f", $this->opacity / 100)
        : $this->opacity;
    $opacity = ($opacity > 1) ? 1.0 : $opacity;
    $options = join(', ', array(
                    $points,
                    $this->color(),
                    $this->thickness,
                    $opacity
                ));
    $this->overlay = "    $map_id.addOverlay($id);\n";
    return "    var $id = new GPolyline($options);\n";
  }
  function color() {
    // This method returns only web-safe colors.
    $color = preg_replace("/\\\'/",'',$this->color);
    if (0 === strpos($color, '#')) $color = substr($color, 1);
    // break into hex 3-tuple
    $cutpoint = ceil(strlen($color) / 2) - 1;
    $rgb = explode(':', wordwrap($color, $cutpoint, ':', $cutpoint), 3);

    $out = '';
    foreach($rgb as $r) {
        if (strlen($r) == 1) $r .= $r; # Expand single value colors.
        $r = (isset($r)) ? hexdec($r) : 0; # Hex to Dec.
        if ($GmaForceWebsafe) $r = (round($r/51) * 51); # Make web-safe
        $out .= str_pad(dechex($r), 2, '0', STR_PAD_LEFT); # Code color.
    }
    return "'#$out'";
  }
}

class GmaMap
{
    var $map_views = array(
      'hyb' => 'G_HYBRID_MAP',
      'nor' => 'G_NORMAL_MAP',
      'sat' => 'G_SATELLITE_MAP',
    );
    var $controls_js = array(
      'large' => 'new GLargeMapControl()',
      'maptype' => 'new GMapTypeControl()', # allow toggle b/w map types.
      'overview' => 'new GOverviewMapControl()', # allow map controls.
      'small' => 'new GSmallMapControl()',
      'type' => 'new GMapTypeControl()',
    );
    var $controls = array();
    function GmaMap($id='map') {
      global $GmaDefaults;
      $this->id = $id;
      $this->height = $GmaDefaults['height'];
      $this->view = $GmaDefaults['view'];
      $this->width = $GmaDefaults['width'];
      $this->zoom = $GmaDefaults['zoom'];
      $this->setControl((array) explode(',', $GmaDefaults['ctrl']));
      $this->lat = "null";
      $this->lon = "null";
    }
    function setControl($controls) {
      foreach ((array) $controls as $c) {
        if ($c[0] == '-' && array_key_exists(substr($c,1), $this->controls)) {
          unset($this->controls[substr($c,1)]);
        }
        else {
          $this->controls[$c] = 1;
        }
      }
    }
    function getControls() {
        $ret = '';
        $id = $this->id;
        foreach (array_keys($this->controls) as $c) {
          if ($c == 'small' && array_key_exists('large', $this->controls)){
            unset($this->controls[$c]);
          }
          elseif ($c == 'large' && array_key_exists('small', $this->controls)){
            unset($this->controls[$c]);
          }
          elseif ($c[0] != '-') {
            $c = $this->controls_js[$c];
            $ret .= "    $id.addControl($c);\n";
          }
        }
        return $ret;
    }
    function view() {
        return $this->map_views[strtolower(substr($this->view,0,3))];
    }
}
class GmaPoint
{
  var $directions = '';
  var $icon = '';
  var $lat = 0.0;
  var $lon = 0.0;
  var $message = '';
  var $title = '';
  function GmaPoint($lat='', $lon='', $map_id='map') {
    global $GmaPointPopulation;
    $this->id = $GmaPointPopulation++;
    if ($lat && $lon) { $this->lat = $lat; $this->lon = $lon; }
    $this->map_id = $map_id;
  }
  function icon() { return $this->_quote($this->icon);}
  function point($map_id) {
    $fromto = $this->fromto();
    $icon = $this->icon();
    $lat = $this->lat;
    $lon = $this->lon;
    $text = $this->message();
    $title = $this->_quote($this->title);
    return ($map_id == $this->map_id) 
        ? "    addGmaPoint($map_id,'$lat','$lon',$title,$text,$fromto,$icon);\n"
        : '';
  }
  function fromto() { return ($this->fromto == 'y') ? 1 : 0; }
  function _quote($i) { return ($i) ? $this->_jsstrip("'$i'") : "''"; }
  function _jsstrip($i) { return $i;} # TODO: Strip Javascript.
  function message() {
      $m = $this->message;
      if ($m) { 
          $m = preg_replace('/&lt;/','<', $m);
          $m = preg_replace('/&gt;/','>', $m);
      } 
      return $this->_quote($m);
  }
  function marker() {
      $index = $this->marker;
      if (!$index) { return ''; }
      $marker = "// createMarker(point, '$index', '');";
      return $marker;
  }
  function wikilink($anchor) {
    if (!$anchor) { return ''; }
    $anchor = $this->_jsstrip($anchor);
  }
  function anchor($anchor) {
    if (!$anchor) { return ''; }
    $anchor = $this->_jsstrip($anchor);
    $id = $this->id;
    $map_id = $this->map_id;
    $link =  "<a name='{$anchor}'></a>"
            ."<a class='gmalink' href='javascript:makeGmalink({$map_id},{$id});'>{$anchor}</a>";
    $this->note .= "<a href=\'#$anchor\'>$anchor</a>";
    return "$link";
  }
} // End Class GmaPoint
function gmaBrowserFix() { }
function gmaAddressLookup($a) {
    global $GmaCacheDir, $GmaKey, $GmaScheme;

    $res = false;
    $fname = preg_replace("/\/\//", "/", "$GmaCacheDir/".md5($a).".txt");

    if ($GmaCacheDir && file_exists($fname)) {
        $f = fopen($fname, 'r');
        if ($f) { $res = fread($f, filesize($fname)); fclose($f);}
    }
    if(!$res) {
        $url = sprintf(
                '%s://maps.google.com/maps/geo?&q=%s&output=csv&key=%s',
		$GmaScheme,
                rawurlencode($a),
                $GmaKey
               );
        $res = file_get_contents($url);
    }
    $coords = array();
    if ($res) {
        $bits = explode(',',$res);
        if($bits[0] != 200) return false;
        if ($GmaCacheDir && !file_exists($fname)) {
            $f = fopen($fname, 'w');
            if ($f) {fwrite($f, $res); fclose($f); }
        }
        return array('lat'=>$bits[2], 'lon'=>$bits[3]);
    }
    return $coords;
}
function gmaCleanup() {
  global $HTMLHeaderFmt, $HTMLStylesFmt, $HTMLFooterFmt;
  global $GmaEnable, $GmaVersion, $GmaScript;
  global $GmaMaps, $GmaPoints, $GmaLines;
  global $GmaKey, $GmaScheme, $GmaDebugMsg, $GmaDefaults;

  GmaDoIEFix();
  $HTMLHeaderFmt[] 
     =  '<style type=\'text/css\'>v:* { behavior:url(#default#VML); }</style>'
       ."\n<script src='$GmaScheme://maps.google.com/maps?file=api&v=2.70&key="
       .$GmaKey."' type='text/javascript'></script>";
  $HTMLHeaderFmt[] 
     =  "\n<script language='javascript' src='\$FarmPubDirUrl/scripts/gmaJs.js'>"
       ."</script>\n"; 
  $HTMLStylesFmt['gmap_api'] = '';

  $id = 'map';
  $map_ids = array_keys($GmaMaps);
  $mapcode = '';

  foreach ($map_ids as $map_id) {
      #--------------------------------
      # Set the HTML
      $height = $GmaMaps[$map_id]->height;
      $width = $GmaMaps[$map_id]->width;
      $clat = $GmaMaps[$map_id]->lat;
      $clon = $GmaMaps[$map_id]->lon;
      $float = ($GmaMaps[$map_id]->float) 
        ? " float: {$GmaMaps[$map_id]->float};" : '';

      $overlay = '';
      $points = '';
      $lines = '';

      foreach ($GmaPoints as $p) { 
          $points .= $p->point($map_id); 
      }
      foreach ($GmaLines as $l) { 
          $lines .= $l->line($map_id); 
          $overlay .= $l->overlay;
      }

      $HTMLStylesFmt['gmap_api'] 
         .= "div#$map_id{height: $height; width:$width;$float}";

      GmaDebug(print_r($GmaMaps[$map_id], 1));
      $controls = $GmaMaps[$map_id]->getControls();
      $view = $GmaMaps[$map_id]->view();
      $zoom = $GmaMaps[$map_id]->zoom or 'null';
      $mapcode .=<<<MAPCODE

    var $map_id = new GMap2(document.getElementById('$map_id'));
    var bounds = new GLatLngBounds();
$points
$lines
    setGmaMapCenter($map_id, $view, $zoom, $clat, $clon);
    $map_id.addControl(new GScaleControl());
$controls
$overlay
    doGmaOverlay($map_id);
MAPCODE;

    }

  $debug = ($GmaDebug) ? implode("\n", (array) $GmaDebugMsg) : '';
  $defaults = $GmaDefaults; 
  $defaults = preg_replace('/Array|\(\\n|\)/', '', print_r($defaults, 1));

  $HTMLFooterFmt['gma'] =<<<GMASCRIPT
  $debug
  <!-- GMA Site Default Controls:
  $defaults -->
  <script type="text/javascript">
  //<![CDATA[
  // Copyright (c) 2006, Benjamin C. Wilson. All Rights Reserved.
  // Google Map API for PmWiki, $GmaVersion.
  // This copyright statement must accompany this script.
  if (GBrowserIsCompatible()) {
    var from_htmls = [];
    var htmls = [];
    var markers = [];
    var points = [];
    var to_htmls = [];
    var i = 0;
$mapcode
  }
</script>
GMASCRIPT;
    global $GmaFooterFmt;
    $GmaFooterFmt = $HTMLFooterFmt['gma'];
}
function gmaDebug($m) {
  // (null) gmaDebug(string);
  //
  // Packs end-of-cycle debugging information.
  global $GmaDebug, $GmaDebugMsg;
  array_push($GmaDebugMsg, "<pre>$m</pre>");
}
function gmaLLZError($type, $lat, $lon) {
  // (bool) gmaLLZError(type, lat, lon);
  //
  // function flags error when the type requires a lattitude or longitude.
  if ($type == 'map') return 0;
  if (!$lat && !$lon) return 1;
}
function gmaMarkup($type, $args) {
  // (string) gmaMarkup(type, args);
  //
  // This function converts all PmWiki markup into GMA objects and attributes
  // and reconciles changes to the site-default.

  global $GmaDefaults, $GmaDebug;
  $type = preg_replace("/:.*/", '', $type);
  gmaSpoofLocale();
  $ret = '';

  $opts = parseArgs($args);
  $opts = array_merge($GmaDefaults, $opts);
  GmaDebug("OPTIONS:".print_r($opts,1));
  GmaDebug("CONTROLS:".print_r($controls,1));

  // GMA accepts either ZIP code or lat/lon.
  if ($opts['zip'])
    list($opts['lat'], $opts['lon']) = gmaZIP2LatLon($opts['zip']);
  if ($opts['addr']) {
    $c = gmaAddressLookup($opts['addr']);
    if ($c) {
      $opts['lat'] = $c['lat'];
      $opts['lon'] = $c['lon'];
    }
  }
  #die("$type");
  if (gmaLLZError($type, $opts['lat'], $opts['lon']) && $type != 'show')
    return 'GMA Error: No Lat/Lon or ZIP given (or incomplete).';

  // This switch handles the behavior of the various GMA Types.
  switch($type) {
    // Gma Type: Line "(:gma-line lat lon [options]:)"
    case ('line'):
        global $GmaLines;
        $lid = GmaDefaults('line'.$opts['id'], 'line0');

        if (!array_key_exists($lid, $GmaLines)) {
          $GmaLines[$lid] = new GmaLine($lid);
        }
        $line = &$GmaLines[$lid];

        $line->mapid = getMapId($opts['mapid']);
        if ($opts['color']) $line->color = $opts['color'];
        if ($opts['thickness']) $line->thickness = $opts['thickness'];
        if ($opts['opacity']) $line->opacity = $opts['opacity'];

        $line->addPoint($opts['lat'], $opts['lon']);
        $ret .= ($GmaDebug) ?  "<pre>".Keep(print_r($line,1)) : '';
        break;

    // Gma Type: Map "(:gma-map [options]:)"
    case ('map'):
        global $GmaMaps;
        global $MarkupFrame;
        // Create The Map Object and set attributes.
        $id = getMapId($opts['mapid']);
        $GmaMaps[$id] = new GmaMap($id);
        if ($opts['ctrl']) $controls = (array) explode(',', $opts['ctrl']);
        $GmaMaps[$id]->setControl($controls);
        if ($opts['zoom'])   $GmaMaps[$id]->zoom = $opts['zoom'];
        if ($opts['float'])  $GmaMaps[$id]->float = $opts['float'];
        if ($opts['view'])   $GmaMaps[$id]->view = $opts['view'];
        if ($opts['height']) $GmaMaps[$id]->height = $opts['height'];
        if ($opts['width'])  $GmaMaps[$id]->width = $opts['width'];
        if ($opts['lat'] && $opts['lon']) {
            $GmaMaps[$id]->lat = $opts['lat'];
            $GmaMaps[$id]->lon = $opts['lon'];
        }

        // Trigger the end-of-markup addition of the Javascript,
        // and give the map's target.
        if (!$MarkupFrame[0]['posteval']['mymarkup'])
          $MarkupFrame[0]['posteval']['mymarkup'] = 'gmaCleanup();';
        $ret = Keep("<div id='{$GmaMaps[$id]->id}'></div><div id='{$GmaMaps[$id]->id}-message'></div>");
        $ret .= ($GmaDebug) ?  "<pre>".Keep(print_r($GmaMaps[$id],1)) : '';
        break;

    // Gma Type: Point "(:gma-point lat lon [options]:)"
    case ('point'):
        global $GmaPoints;
        $map_id = getMapId($opts['mapid']);

        // Build the Point object.
        $point = new GmaPoint($opts['lat'], $opts['lon'], $map_id);
        $point->directions = $opts['daddr'];
        $point->icon = $opts['marker'];
        $point->message .= $opts['text'];
        $point->fromto = $opts['fromto'];
        if ($opts['link']) $ret .= $point->anchor($opts['link']);
        // Add the point to the Collection.
        array_push($GmaPoints, $point);
        break;
    case('show'):
        $ret = gmaShowGoogleMapfiles();
        break;
    // Gma Type: (Invalid)
    default:
        $ret = "GMA Error: Type Unknown ($type)";
  }
  gmaSpoofLocale();
  return $ret;
}
function getMapId($o) { return ($o) ? $o : 'map'; }

function gmaSpoofLocale() {
    // (null) gmaSpoofLocale(null);
    //
    // Provided by HelgeLarsen. This allows for non-US locales to behave as
    // normal. I moved it into a function for smoother toggle, but this may be
    // OBE.
    global $GmaHoldLocale, $GmaLocaleToggle;
    if ($GmaLocaleToggle) {
      setlocale(LC_NUMERIC,$GmapHoldLocale);
    }
    else {
      $GmapHoldLocale = setlocale(LC_NUMERIC,'0');
      setlocale(LC_NUMERIC,'en_US');
    }
    $GmaLocaleToggle = ($GmaLocalToggle) ? 0 : 1;
}
function gmaZIP2LatLon($z) {
  // (array) gmaZIP2LatLon(int);
  //
  // This function allows a site to use ZIP codes for lat-lon, when configured.
  // Specifically, the function checks for the ZIP file and then greps the
  // answer.
  $zip_file = GMAPATH.'/library/gma_zip.csv';
  if (file_exists($zip_file) && $zip_file != '') {
    exec("grep '^$z' $zip_file", $r);
    $x = split(',', $r[0]);
    list($lat, $lon) = array($x[1], $x[2]);
    $lon = preg_replace('/^(.)0/', "$1", $lon);
    return array($lat, $lon);
  }
  return array(null,null); # No zip file.
}
function GmaDoIEFix() {
  global $GmaIEFix;
  if (get_cfg_var('browscap')) {
   $browser=get_browser(); //If available, use PHP native function
  }
  else {
   require_once(GMAPATH."library/php-local-browscap.php");
   $browser=get_browser_local(null, false, GMAPATH."/library/browscap.ini");
  }
  $GmaIEFix = (preg_match('/IE/', $browser->browser))
    ? " xmlns='http://www.w3.org/1999/xhtml' xmlns:v='urn:schemas-microsoft-com:vml'"
    : '';
}
function gmaShowGoogleMapfiles() {
    global $GmaGoogleMapfilesUrl;
    $pals = array('pal2','pal3','pal4','pal5');
    $display = array();
    $cells = array('(:cellnr:)','(:cell:)');
    foreach ($pals as $pal) {
        $display[] = "(:table id=gmashow:)\n(:cellnr colspan=8 class=header:)$pal\n";
        for($i = 0; $i < 64; $i++) {
            $icon = implode('/', array(
                $GmaGoogleMapfilesUrl
                ,$pal
                ,"icon$i.png"
                ));
            $cell = ($i % 8) ? $cells[1] : $cells[0];
            $display[] = "$cell$icon (icon$i)";
        }
        $display[] = '(:tableend:)';
    }
    return implode("\n", $display);
}
