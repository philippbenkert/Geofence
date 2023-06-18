<?php

class Geofence extends IPSModule {

    public function Create() {
        parent::Create();

        // Überprüfen Sie die Installationsbedingungen
        $this->checkInstallationConditions();

        $this->RegisterPropertyInteger('Latitude', 0);
        $this->RegisterPropertyInteger('Longitude', 0);
        $this->RegisterPropertyInteger('Altitude', 0);
        $this->RegisterPropertyInteger('Speed', 0);

        $this->RegisterPropertyString('GoogleMapsAPIKey', '');

        $this->RegisterVariableString('MapHTMLBox', 'Map', '~HTMLBox');
    }

    private function checkInstallationConditions() {
        // Überprüfen Sie die PHP-Version
        if (version_compare(PHP_VERSION, '7.2.0', '<')) {
            $this->LogMessage("PHP version 7.2.0 or higher is required", KL_ERROR);
            return;
        }

        // Überprüfen Sie die Verfügbarkeit der cURL-Erweiterung (wird für API-Anfragen benötigt)
        if (!extension_loaded('curl')) {
            $this->LogMessage("The cURL PHP extension is required", KL_ERROR);
            return;
        }

        // Überprüfen Sie die Schreibrechte für das Verzeichnis, in das die Geotracking-Daten geschrieben werden
        if (!is_writable('/var/bin/symcon/modules/Geofence')) {
            $this->LogMessage("The directory /var/bin/symcon/modules/Geofence is not writable", KL_ERROR);
            return;
        }

        // Überprüfen Sie die Internetverbindung
        $connected = @fsockopen("www.google.com", 80); 
        if (!$connected){
            $this->LogMessage("Internet connection is required", KL_ERROR);
            return;
        }
        fclose($connected);
    }
    public function ApplyChanges() {
        parent::ApplyChanges();

        $this->validateVariableId($this->ReadPropertyInteger('Latitude'), "Latitude variable does not exist");
        $this->validateVariableId($this->ReadPropertyInteger('Longitude'), "Longitude variable does not exist");
        $this->validateVariableId($this->ReadPropertyInteger('Altitude'), "Altitude variable does not exist");
        $this->validateVariableId($this->ReadPropertyInteger('Speed'), "Speed variable does not exist");
    }

    public function UpdateGeotracking() {
        $latitude = GetValue($this->ReadPropertyInteger('Latitude'));
        $longitude = GetValue($this->ReadPropertyInteger('Longitude'));
        $altitude = GetValue($this->ReadPropertyInteger('Altitude'));
        $speed = GetValue($this->ReadPropertyInteger('Speed'));

        $this->validateValues($latitude, $longitude, $altitude, $speed);

        $googleMapsAPIKey = $this->ReadPropertyString('GoogleMapsAPIKey');
        $this->validateAPIKey($googleMapsAPIKey);

        $geotrackingData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'speed' => $speed
        ];

        $this->writeToFile($geotrackingData);

        $data = $this->readFromFile();

        $htmlCode = $this->generateHTML($data, $googleMapsAPIKey, $latitude, $longitude);

        $this->updateHTMLBox($htmlCode);
    }

    private function validateVariableId($variableId, $errorMessage) {
        if (!IPS_VariableExists($variableId)) {
            $this->LogMessage($errorMessage, KL_ERROR);
        }
    }

    private function validateValues($latitude, $longitude, $altitude, $speed) {
        if (!is_numeric($latitude) || !is_numeric($longitude) || !is_numeric($altitude) || !is_numeric($speed)) {
            $this->LogMessage("Invalid values for latitude, longitude, altitude, or speed", KL_ERROR);
            return;
        }
    }

    private function validateAPIKey($googleMapsAPIKey) {
        if (empty($googleMapsAPIKey)) {
            $this->LogMessage("Invalid Google Maps API key", KL_ERROR);
            return;
        }
    }

    private function writeToFile($geotrackingData) {
        if (file_put_contents('/var/bin/symcon/modules/Geofence/geotracking.json', json_encode($geotrackingData), FILE_APPEND) === false) {
            $this->LogMessage("Failed to write to file", KL_ERROR);
            return;
        }
    }

    private function readFromFile() {
        $jsonString = file_get_contents('/var/bin/symcon/modules/Geofence/geotracking.json');
        $data = json_decode($jsonString```php
, true);

        if ($data === null) {
            $this->LogMessage("Failed to read from file", KL_ERROR);
            return;
        }

        return $data;
    }

    private function generateHTML($data, $googleMapsAPIKey, $latitude, $longitude) {
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

        return $htmlCode;
    }

    private function updateHTMLBox($htmlCode) {
        if (SetValue($this->GetIDForIdent('MapHTMLBox'), $htmlCode) === false) {
            $this->LogMessage("Failed to update MapHTMLBox", KL_ERROR);
        }
    }
}
