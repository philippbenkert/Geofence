<?php

class Geofence extends IPSModule {

    public function Create() {
        parent::Create();

        // Erstellen Sie die Eigenschaften, die die Variablen-IDs enthalten
        $this->RegisterPropertyInteger('Latitude', 0);
        $this->RegisterPropertyInteger('Longitude', 0);
        $this->RegisterPropertyInteger('Altitude', 0);
        $this->RegisterPropertyInteger('Speed', 0);

        // Registrieren Sie die Eigenschaft für den API-Schlüssel
        $this->RegisterPropertyString('GoogleMapsAPIKey', '');

        // Erstellen Sie die HTMLBox, die die Karte enthalten wird
        $this->RegisterVariableString('MapHTMLBox', 'Map', '~HTMLBox');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    public function UpdateGeotracking() {
        $latitude = GetValue($this->ReadPropertyInteger('Latitude'));
        $longitude = GetValue($this->ReadPropertyInteger('Longitude'));
        $altitude = GetValue($this->ReadPropertyInteger('Altitude'));
        $speed = GetValue($this->ReadPropertyInteger('Speed'));
        
        // Holen Sie sich den Google Maps API-Schlüssel
        $googleMapsAPIKey = $this->ReadPropertyString('GoogleMapsAPIKey');

        $geotrackingData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'speed' => $speed
        ];

        file_put_contents('/var/bin/symcon/modules/Geofence/geotracking.json', json_encode($geotrackingData), FILE_APPEND);

        $jsonString = file_get_contents('/var/bin/symcon/modules/Geofence/geotracking.json');
        $data = json_decode($jsonString, true);

        $htmlCode = '<!DOCTYPE html>
                    <html>
                    <body>
                    <div id="map" style="height: 400px; width: 500px;"></div>
                    <script>
                    function initMap() {
                        var map = new google.maps.Map(document.getElementById("map"), {
                            zoom: 6,
                            center: {lat: ' . $latitude . ', lng: ' . $longitude . '},
                            mapTypeId: "terrain"
                        });

                        var flightPlanCoordinates = [';

        foreach($data as $item) {
            $htmlCode .= '{lat: ' . $item['latitude'] . ', lng: ' . $item['longitude'] . '},';
        }

        $htmlCode .= '];

        var flightPath = new google.maps.Polyline({
                            path: flightPlanCoordinates,
                            geodesic: true,
                            strokeColor: "#FF0000",
                            strokeOpacity: 1.0,
                            strokeWeight: 2
                        });

        flightPath.setMap(map);';

        foreach($data as $item) {
            $htmlCode .= 'var marker = new google.maps.Marker({
                              position: {lat: ' . $item['latitude'] . ', lng: ' . $item['longitude'] . '},
                              map: map,
                              title: "Speed: ' . $item['speed'] . ' km/h, Altitude: ' . $item['altitude'] . ' meters"
                          });';
        }

        $htmlCode .= '}
                    </script>
                    <script async defer src="https://maps.googleapis.com/maps/api/js?key=' . $googleMapsAPIKey . '&callback=initMap">
                    </script>
                    </body>
                    </html>';

        SetValue($this->GetIDForIdent('MapHTMLBox'), $htmlCode);
    }
}
