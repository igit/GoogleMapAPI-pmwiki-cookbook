// Copyright (c) 2006, Benjamin C. Wilson. All Rights Reserved.
// Google Map API for PmWiki.
// This copyright statement must accompany this script.

// Version: 2.2.0-Pre8+

var geocoder = new GClientGeocoder();
var linked = Array();

// Creating a template for most inverted, teardrop markers.
var earthIcon = new GIcon();
earthIcon.iconSize = new GSize(32, 32);
earthIcon.iconAnchor = new GPoint(32, 32);
earthIcon.shadowSize = new GSize(32, 32);
earthIcon.infoWindowAnchor = new GPoint(9, 2);
earthIcon.infoShadowAnchor = new GPoint(18, 25);

var baseIcon = new GIcon();
baseIcon.iconSize = new GSize(24, 32);
baseIcon.iconAnchor = new GPoint(9, 32);
baseIcon.shadowSize = new GSize(50, 32);
baseIcon.shadow = "http://www.google.com/mapfiles/shadow50.png";
baseIcon.infoWindowAnchor = new GPoint(9, 2);
baseIcon.infoShadowAnchor = new GPoint(18, 25);

function tohere(k) { markers[k].openInfoWindowHtml(to_htmls[k]); }
function fromhere(k) { markers[k].openInfoWindowHtml(from_htmls[k]); }
function makeGmalink(map, k) { map.panTo(points[k]); markers[k].openInfoWindowHtml(htmls[k]); window.scrollTo(map.top,0); }
function doGmaOverlay(map) { for (k in markers) { if (markers[k]) { map.addOverlay(markers[k]); } } }
function showAddress(address) {
  geocoder.getLatLng(
    address,
    function(point) {
      if (!point) {
        alert(address + " not found");
      } else {
        map.setCenter(point, 13);
        var marker = new GMarker(point);
        map.addOverlay(marker);
        marker.openInfoWindowHtml(address);
      }
    }
  );
}
function setGmaMapCenter(map, type, zoom,lat,lon) {
  if (!zoom) zoom = map.getBoundsZoomLevel(bounds);
  var clat = (lat==null)
    ? (bounds.getNorthEast().lat() + bounds.getSouthWest().lat()) /2
    : lat;
  var clon = (lon==null)
    ? (bounds.getNorthEast().lng() + bounds.getSouthWest().lng()) /2
    : lon;
  map.setCenter(new GLatLng(clat,clon), zoom, type);
}
// This function picks up the click and opens the corresponding info window
function makeMarkerIcon(ba,ov) {
  var label = { 'anchor':new GLatLng(4,4), 'size':new GSize(12,12), 'url':overlay[ov] };
  var icon = new GIcon(G_DEFAULT_ICON, background[ba], label);
  return icon;
}
function addGmaPoint(map,lat,lon,name,msg,fromto,marker) {
  var point = new GLatLng(lat,lon); 
  var marker = (marker) ? createMarker(point, marker) : new GMarker(point);
  bounds.extend(point);

  // The info window version with the 'to here' form open
  if (msg || fromto) {
    name = (name) ? '<b>'+name+'</b>\n' : '';
    var from_directions = '';
    var inactive = '';
    var to_directions = '';
    if (fromto) {
        to_directions = '<br>Directions: <b>To here</b> -'
                    + '<a href="javascript:fromhere(' + i + ')">From here</a>' 
                    + '<br>Start address:<form action="http://maps.google.com/maps" method="get" target="_blank">' 
                    + '<input type="text" size=40 maxlength=80 name="saddr" id="saddr" value="" /><br>' 
                    + '<input value="Get Directions" type="submit">' 
                    + '<input type="hidden" name="daddr" value="'
                    + point.lat() + ',' + point.lng() + '"/>';
        // The info window version with the 'to here' form open
        from_directions = '<br>Directions: <a href="javascript:tohere(' + i + ')">To here</a> - <b>From here</b>' 
                    + '<br>Start address:<form action="http://maps.google.com/maps" method="get" target="_blank">' 
                    + '<input type="text" size=40 maxlength=80 name="daddr" id="saddr" value="" /><br>' 
                    + '<input value="Get Directions" TYPE="submit">' 
                    + '<input type="hidden" name="daddr" value="'
                    + point.lat() + ',' + point.lng() + '"/>';
        inactive = '<br />Directions: <a href="javascript:tohere('+i+')">To here</a> - <a href="javascript:fromhere('+i+')">From here</a>';
      }
      // The inactive version of the direction info
      //msg = name + msg + '<br />Directions: <a href="javascript:tohere('+i+')">To here</a> - <a href="javascript:fromhere('+i+')">From here</a>';

      to_htmls[i] = msg + to_directions
      from_htmls[i] = msg + from_directions
      msg = name + msg + inactive;
      GEvent.addListener(marker, 'click', function() { marker.openInfoWindowHtml(msg); map.panTo(point); });
  }
  points[i] = point;
  markers[i] = marker;
  htmls[i] = msg;
  i++;
  return marker;
}
function createMarker(p, l) {
  // Create a lettered icon for this point using our icon class
  var PalIcon = /(\d):(\d+)/;
  var MarkerIcon = /(^[A-Z])/;
  var m = l.match(PalIcon);
  var i = '';
  if (m != null) {
    i = new GIcon(earthIcon);
    i.image = "http://maps.google.com/mapfiles/kml/pal" + m[1] + "/icon" + m[2] + ".png";
    i.shadow = "http://maps.google.com/mapfiles/kml/pal" + m[1] + "/icon" + m[2] + "s.png";
  }
  else {
    i = new GIcon(baseIcon);
    m = l.match(MarkerIcon);
    i.image = "http://www.google.com/mapfiles/marker" + m[0] + ".png";
  }
  return new GMarker(p, i);
}

