<?php

class GeoTracker extends IPSModule {

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

        // Register for updates of the source variables
        $this->RegisterMessage($this->GetIDForIdent('Latitude'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('Longitude'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('Altitude'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('Speed'), VM_UPDATE);
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Update the map when the module is updated
        $this->UpdateGeotracking();
    }

    public function MessageSink($TimeStamp, $SenderId, $Message, $Data) {
        parent::MessageSink($TimeStamp, $SenderId, $Message, $Data);

        // Update the map when one of the source variables is updated
        if ($Message == VM_UPDATE) {
            $this->UpdateGeotracking();
        }
    }

    public function UpdateGeotracking() {
        $latitude = GetValue($this->ReadPropertyInteger('Latitude'));
        $longitude = GetValue($this->ReadPropertyInteger('Longitude'));
        $altitude = GetValue($this->ReadPropertyInteger('Altitude'));
        $speed = GetValue($this->ReadPropertyInteger('Speed'));

        if (!$this->validateValues($latitude, $longitude, $altitude, $speed)) {
            $this->LogMessage("Invalid values for latitude, longitude, altitude, or speed", KL_ERROR);
            return;
        }

        $googleMapsAPIKey = $this->ReadPropertyString('GoogleMapsAPIKey');
        if (!$this->validateAPIKey($googleMapsAPIKey)) {
            $this->LogMessage("Invalid Google Maps API key", KL_ERROR);
            return;
        }

        $geotrackingData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'speed' => $speed
        ];

        // Save the geotracking data to the buffer
        $this->SetBuffer('GeotrackingData', json_encode($geotrackingData));

        if (!$this->writeToFile($geotrackingData)) {
            $this->LogMessage("Failed to write to file", KL_ERROR);
            return;
        }

        $data = $this->readFromFile();
        if ($data === null) {
            $this->LogMessage("Failed to read from file", KL_ERROR);
            return;
        }

        $htmlCode = $this->generateHTML($data, $googleMapsAPIKey, $latitude, $longitude);

        if (!$this->updateHTMLBox($htmlCode)) {
            $this->LogMessage("Failed to update MapHTMLBox", KL_ERROR);
            return;
        }
    }

    private function validateVariableId($variableId) {
        return IPS_VariableExists($variableId);
    }

    private function validateValues($latitude, $longitude, $altitude, $speed) {
        return is_numeric($latitude) && is_numeric($longitude) && is_numeric($altitude) && is_numeric($speed);
    }

    private function validateAPIKey($googleMapsAPIKey) {
        return !empty($googleMapsAPIKey);
    }

    private function writeToFile($geotrackingData) {
        return file_put_contents('/var/bin/symcon/modules/Geofence/geotracking.json', json_encode($geotrackingData), FILE_APPEND) !== false;
    }

    private function readFromFile() {
        $jsonString = file_get_contents('/var/bin/symcon/modules/Geofence/geotracking.json');
        $data = json_decode($jsonString, true);

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
        return SetValue($this->GetIDForIdent('MapHTMLBox'), $htmlCode) !== false;
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
}
