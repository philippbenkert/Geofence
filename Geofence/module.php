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
}

private function validateVariableId($id) {
    if ($id == 0) {
        return false;
    }
    if (!IPS_VariableExists($id)) {
        return false;
    }
    return true;
}    

private function validateValues($latitude, $longitude, $altitude, $speed) {
    if (!is_numeric($latitude) || !is_numeric($longitude) || !is_numeric($altitude) || !is_numeric($speed)) {
        return false;
    }
    if ($latitude < -90 || $latitude > 90) {
        return false;
    }
    if ($longitude < -180 || $longitude > 180) {
        return false;
    }
    if ($altitude < -10000 || $altitude > 10000) {
        return false;
    }
    if ($speed < 0) {
        return false;
    }
    return true;
}


private function validateAPIKey($apiKey) {
    if (empty($apiKey)) {
        return false;
    }

    // Senden Sie eine einfache Anfrage an die Google Maps API
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=40.714224,-73.961452&key=$apiKey";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    // Überprüfen Sie, ob die Antwort einen Fehler enthält, der auf einen ungültigen API-Schlüssel hinweist
    if (isset($data['error_message'])) {
        $this->LogMessage("Invalid Google Maps API key: " . $data['error_message'], KL_ERROR);
        return false;
    }

    return true;
}

private function writeToFile($data) {
    $filePath = __DIR__ . '/geotracking.json';
    $jsonData = json_encode($data);
    if (file_put_contents($filePath, $jsonData) === false) {
        return false;
    }
    return true;
}

private function readFromFile() {
    $filePath = __DIR__ . '/geotracking.json';
    if (!file_exists($filePath)) {
        return null;
    }
    $jsonData = file_get_contents($filePath);
    if ($jsonData === false) {
        return null;
    }
    return json_decode($jsonData, true); // Setzen Sie den zweiten Parameter auf true, um ein Array zurückzugeben
}


    
public function ApplyChanges() {
    parent::ApplyChanges();

    // Register for updates of the source variables
    $latitudeId = $this->ReadPropertyInteger('Latitude');
    $longitudeId = $this->ReadPropertyInteger('Longitude');
    $altitudeId = $this->ReadPropertyInteger('Altitude');
    $speedId = $this->ReadPropertyInteger('Speed');

    if ($latitudeId > 0) {
        $this->RegisterMessage($latitudeId, VM_UPDATE);
    }
    if ($longitudeId > 0) {
        $this->RegisterMessage($longitudeId, VM_UPDATE);
    }
    if ($altitudeId > 0) {
        $this->RegisterMessage($altitudeId, VM_UPDATE);
    }
    if ($speedId > 0) {
        $this->RegisterMessage($speedId, VM_UPDATE);
    }

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

    public function GeoTracker_UpdateGeotracking() {
        $this->UpdateGeotracking();
    }
    
    public function UpdateGeotracking() {
    $this->LogMessage("UpdateGeotracking started", KL_NOTIFY);

    $latitudeId = $this->ReadPropertyInteger('Latitude');
    $longitudeId = $this->ReadPropertyInteger('Longitude');
    $altitudeId = $this->ReadPropertyInteger('Altitude');
    $speedId = $this->ReadPropertyInteger('Speed');

    if ($latitudeId == 0 || $longitudeId == 0 || $altitudeId == 0 || $speedId == 0) {
        $this->LogMessage("Variables not selected yet", KL_WARNING);
        return;
    }

    if (!$this->validateVariableId($latitudeId) || !$this->validateVariableId($longitudeId) || !$this->validateVariableId($altitudeId) || !$this->validateVariableId($speedId)) {
        $this->LogMessage("One or more selected variables do not exist", KL_ERROR);
        return;
    }

    // Find the archive instance
    $archiveInstances = IPS_GetInstanceListByModuleID("AC37D48F-2B8E-4B19-B1F2-4D1C9F6CA96A");
    if (count($archiveInstances) == 0) {
        $this->LogMessage("No archive instance found", KL_ERROR);
        return;
    }
    $archiveId = $archiveInstances[0];  // Use the first archive instance

    // Check and enable archiving for the variables
    $variableIds = [$latitudeId, $longitudeId, $altitudeId, $speedId];
    foreach ($variableIds as $variableId) {
        $isLogging = AC_GetLoggingStatus($archiveId, $variableId);
        if (!$isLogging) {
            AC_SetLoggingStatus($archiveId, $variableId, true);
            IPS_ApplyChanges($archiveId);
        }
    }
    $googleMapsAPIKey = $this->ReadPropertyString('GoogleMapsAPIKey');
    if (!$this->validateAPIKey($googleMapsAPIKey)) {
        $this->LogMessage("Invalid Google Maps API key", KL_ERROR);
        return;
    }

    $this->LogMessage("Google Maps API key is valid", KL_NOTIFY);

    $geotrackingData = [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'altitude' => $altitude,
        'speed' => $speed
    ];

    $this->SetBuffer('GeotrackingData', json_encode($geotrackingData));

    if (!$this->writeToFile($geotrackingData)) {
        $this->LogMessage("Failed to write to file", KL_ERROR);
        return;
    }

    $this->LogMessage("Successfully wrote to file", KL_NOTIFY);

    $data = $this->readFromFile();
    if ($data === null) {
        $this->LogMessage("Failed to read from file", KL_ERROR);
        return;
    }

    $this->LogMessage("Successfully read from file", KL_NOTIFY);

    $htmlCode = $this->generateHTML($data, $googleMapsAPIKey, $latitude, $longitude);
    if (!$this->updateHTMLBox($htmlCode)) {
        $this->LogMessage("Failed to update MapHTMLBox", KL_ERROR);
        return;
    }

    $this->LogMessage("Successfully updated MapHTMLBox", KL_NOTIFY);
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
