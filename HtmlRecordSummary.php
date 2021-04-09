<?php
namespace Stanford\HtmlRecordSummary;

require_once "emLoggerTrait.php";

require_once "vendor/autoload.php";

use \REDCap;
use \Piping;
//use \GuzzleHttp;

use Google\Auth\CredentialsLoader;
use GuzzleHttp;
// use GuzzleHttp\HandlerStack;

class HtmlRecordSummary extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $template;
    public $instance;
    private const EXPIRY = "+3 minutes";

	public function redcap_save_record ( $project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $this->emDebug(__FUNCTION__);

        global $Proj;

        $instances = array_filter($this->getSubSettings("instance"));
        foreach ($instances as $i => $instance) {
            $name = $instance['template-name'];

            # Verify save-form if set or continue
            $updateForms = array_filter($instance['update-forms']);
            if (!empty($updateForms)) {
                if (! in_array($instrument, $updateForms)) continue;
            }

            # Verify logic if set is true
            $updateLogic = $instance['update-logic'];
            if (!empty($updateLogic)) {
                $exp = REDCap::evaluateLogic($updateLogic, $project_id, $record, $event_id, $repeat_instance);
                if ( $exp === false ) continue;
            }
            $this->emDebug("Running: $name");

            $summary = $this->applyTemplate($record, $event_id, $repeat_instance, $instance);

            # Save the summary to the specified field
            $saveField = $instance['save-field'];
            if (!empty($saveField)) {
                $payload = [
                    REDCap::getRecordIdField() => $record,
                    $saveField => $summary
                ];
                if ($Proj->longitudinal) {
                    $saveEvent = $instance['save-field-event_id'];
                    $payload['redcap_event_name'] = $saveEvent;
                }
            }
            $q = REDCap::saveData('json', json_encode(array($payload)), 'overwrite');
            if (!empty($q['errors'])) {
                REDCap::logEvent("Failure saving $name summary", json_encode($q['errors']), "", $record, $event_id, $project_id);
                $this->emDebug("Error saving", $payload, $q['errors']);
            }


            # Save the summary as PDF to file if supplied
            $pdfField = $instance['pdf-field'];
            $pdfEventId = $instance['pdf-event-id'];

            // Register a viewing token for the remote pdf converter
            $hash = $this->registerView($name, $summary);
            $this->emDebug("Hash: $hash");

            // Generate the URL endpoint for viewing the content
            $url = $this->getUrl("pages/renderHtml.php",  true, true);
            $url .= "&hash=$hash";
            $this->emDebug("View Url: $url");

            // DEBUG
            // return false;
            // $url = "https://www.google.com";
            $replaceBaseUrl = $this->getPRojectSetting('replace-base-url');
            $this->emDebug($replaceBaseUrl);
            if (!empty($replaceBaseUrl)) {
                global $redcap_base_url;
                $url = str_replace($redcap_base_url,$replaceBaseUrl,$url);
                $this->emDebug("Replacing $replaceBaseUrl with $replaceBaseUrl for $url");
            }

            // Get PDF
            $pdf = $this->getPdf($url);
            if ($pdf == false) {
                $this->emDebug("Failed to get PDF from $url");
                return false;
            }

            // Set full file path in temp directory. Replace any spaces with underscores for compatibility.
            $moduleName = preg_replace("/[^0-9a-zA-Z-]/", "", $this->getModuleName());
            $instanceName = preg_replace("/[^0-9a-zA-Z-]/", "", $name);
            $recordName = preg_replace("/[^0-9a-zA-Z-]/", "", $record);
            $tempFile = APP_PATH_TEMP . $instanceName . "_" . $recordName . "_" . $hash . ".pdf";
            file_put_contents($tempFile, $pdf);

            // Add PDF to edocs_metadata table
            $pdfFile = array(
                'name' => basename($tempFile), 'type' => 'application/pdf',
                'size' => filesize($tempFile), 'tmp_name' => $tempFile);

            $edoc_id = \Files::uploadFile($pdfFile);
            if ($edoc_id == 0) {
                $this->emError("Unable to get edoc id after save", $pdfFile);
                return false;
            }

            // Save it to the record
            $data = [
                $record => [
                    $pdfEventId => [
                        $pdfField => $edoc_id
                    ]
                ]
            ];

            // In order to save an edoc to a file upload field, you currently need to use the underlying method
            $result = \Records::saveData(
                $project_id,
                'array',        //$dataFormat = (isset($args[1])) ? strToLower($args[1]) : 'array';
                $data,          // = (isset($args[2])) ? $args[2] : "";
                'normal',       //$overwriteBehavior = (isset($args[3])) ? strToLower($args[3]) : 'normal';
                'YMD',          //$dateFormat = (isset($args[4])) ? strToUpper($args[4]) : 'YMD';
                'flat',         //$type = (isset($args[5])) ? strToLower($args[5]) : 'flat';
                $group_id,      // = (isset($args[6])) ? $args[6] : null;
                true,           //$dataLogging = (isset($args[7])) ? $args[7] : true;
                true,           //$performAutoCalc = (isset($args[8])) ? $args[8] : true;
                true,           //$commitData = (isset($args[9])) ? $args[9] : true;
                false,          //$logAsAutoCalculations = (isset($args[10])) ? $args[10] : false;
                true,           //$skipCalcFields = (isset($args[11])) ? $args[11] : true;
                [],             //$changeReasons = (isset($args[12])) ? $args[12] : array();
                false,          //$returnDataComparisonArray = (isset($args[13])) ? $args[13] : false;
                false,          //**** $skipFileUploadFields = (isset($args[14])) ? $args[14] : true;
                false,          //$removeLockedFields = (isset($args[15])) ? $args[15] : false;
                false,          //$addingAutoNumberedRecords = (isset($args[16])) ? $args[16] : false;
                false           //$bypassPromisCheck = (isset($args[17])) ? $args[17] : false;
            );

            // $this->emDebug($result);
            if (empty($result['errors'])) {
                \REDCap::logEvent($this->getModuleName(), $pdfField .
                    " updated from template " . $name, "", $record, $event_id);
                $this->emDebug("PDF Updated for record $record with $name summary");
            } else {
                $this->emError("Error saving updated pdf", $data, $result['errors']);
            }
        }
    }


    /**
     * Get a specific instance by the templateName
     * @param $name
     * @return false|mixed
     */
    public function getInstanceByName($name) {
        $instances = array_filter($this->getSubSettings("instance"));
        foreach ($instances as $i => $instance) {
            if ($name == $instance['template-name']) return $instance;
        }
        return false;
    }


    /**
     * Render the HTML from a view request
     */
    public function renderHtml() {
        $hash = $_REQUEST['hash'];
        if (empty($hash)) {
            $this->emDebug("Request missing hash");
            die ("Missing required inputs - check logs");
        }

        $result = $this->obtainView($hash);
        if ($result === false) {
            $this->emDebug("Failed to obtain view for hash $hash");
            die ("Invalid request - check logs");
        }
        list($name, $summary) = $result;
        // $this->emDebug($name, $summary);

        // From the instance name, find the instance definition
        $instance = $this->getInstanceByName($name);
        if (empty($instance)) {
            $this->emDebug("Failed to find instance by name of $name");
            die ("Invalid saved instance - check logs");
        }

        $page = $this->createHtmlPage($summary, $instance);
        $this->emDebug("Page Rendered");
        // $this->emDebug($page);
        print $page;
    }


    /**
     * Depends on GCP Cloud Function to render PDF from HTML page
     * @param $url
     * @return false|mixed|\Psr\Http\Message\ResponseInterface
     * @throws GuzzleHttp\Exception\GuzzleException
     */
    public function getPdf($url) {

        // Obtain a PDF using the HTML to PDF external function
        $functionUrl = $this->getSystemSetting("gcp-function-url");
        $jsonKey = json_decode($this->getSystemSetting("gcp-function-key"),true);

        if (empty($functionUrl) || empty($jsonKey)) {
            $this->emDebug("Missing required system configuration for GCP");
            return false;
        }

        // $targetAudience = "https://us-west2-som-rit-redcap-dev.cloudfunctions.net/html2pdf";
        $targetAudience = $functionUrl;
        $c = CredentialsLoader::makeCredentials($targetAudience, $jsonKey);
        $authToken = $c->fetchAuthToken();  // Array with key 'id_token'

        if (empty($authToken['id_token'])) {
            // Failed to obtain
            $this->emError("Failed to obtain valid authToken - check README for requirements");
            return false;
        }
        // $this->emDebug($authToken['id_token']);

        $client = new GuzzleHttp\Client([
            'base_uri' => $functionUrl
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $authToken['id_token']
        ];

        try {
            $response = $client->request('POST', '', [
                'form_params' => ['url' => $url],
                'headers' => $headers,
            ]);
        } catch (GuzzleHttp\Exception\RequestException $e) {
            // var_dump($e->getMessage());
            $this->emError($e->getMessage());
            // Log error to project logs
            \REDCap::logEvent($this->getModuleName() . " Error", "Failed to get PDF from $url\n\n" . $e->getMessage());
            return false;
        }

        // $this->emDebug($response->getStatusCode(), $response->getHeader('Content-Type'));
        // var_dump($response);
        return $response->getBody();

    }

    /**
     * Return a complete HTML page for a summary/instance
     * @param $summary
     * @param $instance
     * @return string
     */
    public function createHtmlPage($summary, $instance)
    {
        $htmlFramework = $instance['html-framework'];
        $cssTemplate = $instance['css-template'];
        $jsTemplate = $instance['js-template'];

        $htmlTop = "";
        $htmlBottom = "";
        switch ($htmlFramework) {
            case "default":
                $htmlPage = new \HtmlPage();
                $htmlPage->setPageTitle($instance['template-name']);
                ob_start();
                $htmlPage->PrintHeaderExt();
                $htmlTop = ob_get_clean();
                $htmlBottom = "</div></div></div></body></html>";
                break;
            case "bs4":
                $htmlTop = <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">

    <!-- Optional JavaScript -->
    <!-- jQuery first, then Popper.js, then Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.4.1.slim.min.js" integrity="sha384-J6qa4849blE2+poT4WnyKhv5vZF5SrPo0iEjwBvKU7imGFAV0wwj1yYfoRSJoZ+n" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
    <title>{$instance['template-name']}</title>
  </head>
  <body>
HTML;
                $htmlBottom = "</body></html>";
                break;
            default:
                // Vanilla!
        }

        $page = $htmlTop .
            ( empty($cssTemplate) ? "" : "<style type='text/css'>$cssTemplate</style>" ) .
            ( empty($jsTemplate) ? "" : "<script type='text/javascript'>$jsTemplate</script>" ) .
            $summary .
            $htmlBottom;
        return $page;
    }


    /**
     * Apply record data to the defined instance template
     * @param $record
     * @param $event_id
     * @param $repeat_instance
     * @param $instance
     * @return string|null
     */
    public function applyTemplate($record, $event_id, $repeat_instance, $instance) {

        $template = $instance['html-template'];

        // TODO: Not sure if this is necessary
        // if ($Proj->longitudinal) {
        //     $logic = LogicTester::logicPrependEventName($logic, $this->Proj->getUniqueEventNames($this->event_id), $this->Proj);
        //     // $this->emDebug("Prepending event names: $logic");
        // }
        $summary = Piping::replaceVariablesInLabel($template, $record, $event_id, $repeat_instance, null, false, null, false);

        return $summary;
    }


    /**
     * Create a view hash that expires in x minutes
     * @param $summary
     * @return string
     */
    public function registerView($name, $summary) {
        $expires = strtotime(self::EXPIRY);
        $hash = generateRandomHash(20);

        $logId = $this->log($hash, [
            'expires' => $expires,
            'name' => $name,
            'summary' => $summary
        ]);
        return $hash;
    }


    /**
     * Obtain the previously saved summary using a hash
     * Also verifies that the saved summary has not expired
     * @param $hash
     * @return false|mixed|string
     */
    public function obtainView($hash, $markAsViewed=true) {
        $now = strtotime("now");

        $q = $this->queryLogs("select log_id, message, expires, viewed_at, name, summary where message = ? order by log_id desc", [ filter_var($hash, FILTER_SANITIZE_STRING) ]);

        if ($row = db_fetch_assoc($q)) {
            if ($now > $row['expires']) {
                $this->emDebug("The requested view ($hash) has expired.");
                return false;
            }

            if (!empty($row['viewed_at'])) {
                $this->emDebug("This requested view ($hash) was previously viewed.");
                return false;
            }

            if ($markAsViewed) {
                // Update that it was viewed
                $q = $this->query("insert into redcap_external_modules_log_parameters values (?, ?, ?)", [ $row['log_id'], "viewed_at", $now ]);
                // $this->emDebug($q, db_fetch_assoc($q));
            }

            $name = $row['name'];
            $summary = $row['summary'];
            return [$name, $summary];
        }

        return false;
    }

}
