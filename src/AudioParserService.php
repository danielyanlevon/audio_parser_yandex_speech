<?php

namespace Danielyanlevon\AudioParserService;

class AudioParserService
{
    const STORAGE_HOST = 'storage.yandexcloud.net';
    const USE_SSL = true;
    const REGION = 'ru-central1';

    /**
     * @var array
     */
    protected $headers = [];


    /**
     * @var array
     */
    protected $cloudFiles = [];


    /**
     * @var string
     */
    protected $storageApiKeyPrivate = '';

    /**
     * @var string
     */
    protected $storageApiKeyPublic = '';

    /**
     * @var string
     */
    protected $apiKey = '';

    /**
     * @var string
     */
    protected $localFilesDir = '';

    /**
     * @var string
     */
    protected $storageBucket = '';



    /**
     * AudioParserService set params
     * @param array $params =[
     * 'apiKey' => '',
     * 'localFilesDir' => '',
     * 'storageApiKeyPublic' => '',
     * 'storageApiKeyPrivate' => '',
     * 'storageBucket' => ''
     * ];
     * @return mixed
     * @throws \Exception
     */
    public function setParameters(array $params = [])
    {
        $this->apiKey = $params['apiKey'] ?? '';
        $this->storageApiKeyPrivate = $params['storageApiKeyPrivate'] ?? '';
        $this->storageApiKeyPublic = $params['storageApiKeyPublic'] ?? '';
        $this->storageBucket = $params['storageBucket'] ?? '';
        $this->localFilesDir =  ($params['localFilesDir'] ?? '');
        $this->headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Api-Key ' . $this->apiKey
        );

        $this->setS3Params();

        return $this;
    }

    /**
     * Set S3 library params
     *
     * @return void
     * @throws \Exception
     */
    private function setS3Params()
    {
        if (!$this->storageApiKeyPublic or !$this->storageApiKeyPrivate) throw new \Exception('storage private or public key is empty');

        S3::setAuth($this->storageApiKeyPublic, $this->storageApiKeyPrivate);
        S3::$useSSL = self::USE_SSL;
        S3::$endpoint = self::STORAGE_HOST;
        S3::$region = self::REGION;
    }

    /**
     * check local files format and re-format if is not .ogg
     *
     * @params int $chatId
     * @return void
     * @throws \Exception
     */
    private function checkLocalFilesFormat(string $chatId)
    {
        $dir = $this->localFilesDir.$chatId.'/';

        $cdir = scandir($dir);
        if (!$cdir) throw new \Exception('direction is empty');

        foreach ($cdir as $key => $value) {
            if (in_array($value, array(".", ".."))) continue;
            $ext = pathinfo($value, PATHINFO_EXTENSION);
            if (in_array($ext, array('pcm', 'opus', 'wav', 'mp3'))) {
                if (file_exists($dir . pathinfo($value, PATHINFO_FILENAME) . '.ogg')) continue;
                exec("opusenc --bitrate 256 '" . $dir . $value . "' '" . $dir . pathinfo($value, PATHINFO_FILENAME) . ".ogg' --vbr");
                unlink($dir . $value);
            }
        }
    }

    /**
     * get audio file parsed text
     *
     * @param string $operationId
     * @return array
     * @throws \Exception
     */
    private function getText(string $operationId)
    {
        $curl = curl_init();
        $url = "https://operation.api.cloud.yandex.net/operations/{$operationId}";
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $curl_response_text = curl_exec($curl);
        curl_close($curl);
        $response_text = json_decode($curl_response_text);

        if (!$response_text) {
            throw new \Exception('wrong json answer');
        }
        if (!$response_text->done) {
            sleep(2);
            return $this->getText($operationId);
        }
        if (isset($response_text->error)) {
            throw new \Exception($response_text->error->message);
        }
        if (!$response_text->response->chunks) {
            throw new \Exception('Chunk is empty');
        }
        $conversation_parts = [];

        foreach ($response_text->response->chunks as $c) {
            if ($c->alternatives) {
                foreach ($c->alternatives as $k => $a) {
                    if (!$a->text) continue;
                    $conversation_parts[] = $a->text;
                }
            }
        }
        return $conversation_parts;
    }

    /**
     * Set cloud files
     *
     * @return void
     * @throws \Exception
     */
    private function getCloudFiles()
    {
        if (!$this->storageBucket) throw new \Exception('storage bucket name is empty');
        $objects = S3::getObject($this->storageBucket, '');
        if (isset($objects->body->Contents)) {
            foreach ($objects->body->Contents as $o) {
                $this->cloudFiles[] = (string)$o->Key[0];
            }
        }
    }

    /**
     * get file duration and size
     *
     * @param string $audioPath
     * @return array
     * @throws \Exception
     */
    private function getFileDurationAndSize(string $audioPath)
    {
        $time = exec("ffmpeg -i " . escapeshellarg($audioPath) . " 2>&1 | grep 'Duration' | cut -d ' ' -f 4 | sed s/,//");
        list($hms, $milli) = explode('.', $time);
        list($hours, $minutes, $seconds) = explode(':', $hms);
        return [
            'duration'=>($hours * 3600) + ($minutes * 60) + $seconds,
            'size'=>filesize($audioPath)/1024
        ];

    }


    /**
     * modify filename and detect file type (short or long)
     *example file.ogg or file
     *
     * @param string $audioName
     * @param int $chatId
     * @return array
     * @throws \Exception
     */
    public function parseAudio(int $chatId,string $audioName)
    {

        if (pathinfo($audioName, PATHINFO_EXTENSION) != 'ogg')
        {
            $audioName = pathinfo($audioName, PATHINFO_FILENAME) . '.ogg';
        }

        $this->checkLocalFilesFormat($chatId);

        $audioPath=$this->localFilesDir .$chatId.'/'. $audioName;
        if (!file_exists($audioPath))
        {
            throw new \Exception('file '.$audioName.' does not exist in directory ' . dirname($audioPath));
        };

        $fileDurationAndSize = $this->getFileDurationAndSize($audioPath);
        if($fileDurationAndSize['duration']<30 && $fileDurationAndSize['size']<1024)
        {
            return $this->parseShortAudio($audioPath);
        }
        else
        {
            return $this->parseLongAudio($audioPath,$audioName);
        }

    }

    /**
     * get short audio parsed data
     *
     * @param string $audiopath
     * @return array
     * @throws \Exception
     */
    private function parseShortAudio(string $audiopath)
    {
        $file = fopen($audiopath, 'rb');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://stt.api.cloud.yandex.net/speech/v1/stt:recognize?lang=ru-RU&format=oggopus");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Api-Key '.$this->apiKey.'', 'Transfer-Encoding: chunked'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);

        curl_setopt($ch, CURLOPT_INFILE, $file);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($audiopath));
        $res = curl_exec($ch);
        curl_close($ch);
        fclose($file);

        $decodedResponse = json_decode($res, true);
        if (!isset($decodedResponse["result"])) throw new \Exception($decodedResponse["error_message"]);

        return [$decodedResponse["result"]];
    }

    /**
     * get long audio parsed data
     *
     * @param string $audiopath
     * @param string $audioName
     * @return array
     * @throws \Exception
     */
    private function parseLongAudio(string $audioPath,string $audioName){
        $this->getCloudFiles();
        if (!in_array($audioName, $this->cloudFiles))
        {
            $put_file_response = S3::putObjectFile($audioPath, $this->storageBucket, $audioName);
            if ($put_file_response != 'true')
            {
                throw new \Exception($put_file_response);
            }
        }


        $speechkitData = [
            'config' => [
                'profanityFilter' => 'true',
                "languageCode" => "ru-RU",
                "model" => "general:rc",
                'audioEncoding' => 'LINEAR16_PCM',
                'sampleRateHertz' => 48000,
                'audioChannelCount' => 1
            ],
            'audio' => [
                'uri' => "https://" . self::STORAGE_HOST . "/" . $this->storageBucket . "/" . $audioName
            ]
        ];


        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, 'https://transcribe.api.cloud.yandex.net/speech/stt/v2/longRunningRecognize');
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($speechkitData, JSON_UNESCAPED_SLASHES));
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $curl_response = curl_exec($curl);
        $response = json_decode($curl_response);
        curl_close($curl);

        if (!isset($response->done))
        {
            throw new \Exception($response->message);
        }

        if (!$response->id) throw new \Exception('operationId is empty');
        $id = $response->id;
        return $this->getText($id);
    }


    /**
     * get all files in direction
     *
     * @param string $dir
     * @return array
     * @throws \Exception
     */
    function dirToArray($dir)
    {
        $result = array();
        $cdir = scandir($dir);
        if (!$cdir) throw new \Exception('direction is empty');
        foreach ($cdir as $key => $value)
        {
            if (in_array($value, array(".", ".."))) continue;
                if (is_dir($dir . '/' . $value))
                {
                    $result[$value] = $this->dirToArray($dir . '/' . $value);
                } else {
                    $result[] = $value;
                }

        }
        return $result;
    }


    /**
    * parse all audio files
    * @return array
    * @throws \Exception
    */
    public function parseAllFiles()
    {
        $filesInDirectory = $this->dirToArray($this->localFilesDir);
        if(!$filesInDirectory)  throw new \Exception('directory is empty');
        $result=[];
        foreach($filesInDirectory as $dir=>$filesArray)
        {
            if(!$filesArray) continue;
            foreach($filesArray as $file)
            {
                $result[$dir][$file]=$this->parseAudio($dir,$file);
            }
        }
        return $result;
    }
}
