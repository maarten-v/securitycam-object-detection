<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\LocalizedObjectAnnotation;
use Google\Protobuf\Internal\RepeatedField;

class VisionApiProcessor
{
    private ImageAnnotatorClient $vision;
    private array $ignoreLabels;
    private string $logFile;

    public function __construct()
    {
        $this->loadEnvironment();
        $this->initializeVisionClient();
        $this->ignoreLabels = ['Table', 'Chair', 'Coffee table', 'Furniture'];
        $this->logFile = __DIR__ . '/log.txt';
    }

    private function loadEnvironment(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }

    private function initializeVisionClient(): void
    {
        $keyPath = __DIR__ . '/' . $_ENV['GOOGLE_APPLICATION_CREDENTIALS'];
        $this->vision = new ImageAnnotatorClient(['credentials' => $keyPath]);
    }

    public function processImage(): void
    {
        $lastMessageFile = __DIR__ . '/lastmessage.txt';
        $lastMessageTime = file_exists($lastMessageFile) ? (int)file_get_contents($lastMessageFile) : 0;
        $currentTime = time();

        // Check if 10 minutes (600 seconds) have passed since the last notification
        if (($currentTime - $lastMessageTime) <= 600) {
            return; // Exit if less than 10 minutes have passed
        }
        try {
            $imageData = $this->fetchImageFromCamera();
            $objects = $this->detectObjects($imageData);
            $filteredObjects = $this->filterObjects($objects);
            $this->logAndOutputResults($filteredObjects);
            if (!empty($filteredObjects)) {
                $this->sendNotification($imageData, $filteredObjects);
                file_put_contents($lastMessageFile, $currentTime);
            }
        } catch (Exception $e) {
            $this->handleError($e);
        } finally {
            $this->vision->close();
        }
    }

    private function fetchImageFromCamera(): bool|string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://10.0.0.146/ISAPI/Streaming/channels/101/picture');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, 'viewer:' . $_ENV['CAM_PASSWORD']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $imageData = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('Failed to fetch image: ' . curl_error($ch));
        }

        curl_close($ch);
        return $imageData;
    }

    private function detectObjects(string $imageData): RepeatedField
    {
        $image = (new Image())->setContent($imageData);
        $feature = (new Feature())->setType(Type::OBJECT_LOCALIZATION);
        $request = (new AnnotateImageRequest())
            ->setImage($image)
            ->setFeatures([$feature]);

        $response = $this->vision->batchAnnotateImages([$request]);
        return $response->getResponses()[0]->getLocalizedObjectAnnotations();
    }

    private function filterObjects(RepeatedField $objects): array
    {
        $objectsArray = iterator_to_array($objects);

        return array_filter($objectsArray, function (LocalizedObjectAnnotation $object) {
            return !in_array($object->getName(), $this->ignoreLabels);
        });
    }

    private function logAndOutputResults(array $filteredObjects): void
    {
        $items = PHP_EOL . date('Y-m-d H:i:s') . PHP_EOL;
        foreach ($filteredObjects as $object) {
            $items .= $object->getName() . ' ' . round($object->getScore() * 100) . '%' . PHP_EOL;
        }
        echo nl2br($items);
        file_put_contents($this->logFile, $items, FILE_APPEND);
    }

    private function handleError(Exception $e): void
    {
        $errorMessage = 'Error: ' . $e->getMessage() . PHP_EOL;
        echo $errorMessage;
        file_put_contents($this->logFile, $errorMessage, FILE_APPEND);
    }

    private function sendNotification(string $imageData, array $filteredObjects): void
    {
        $message = $this->formatObjectsMessage($filteredObjects);
        $this->pushoverNotification($message, $imageData);
    }

    private function formatObjectsMessage(array $filteredObjects): string
    {
        return implode(', ', array_map(function ($obj) {
            return $obj->getName() . ' (' . round($obj->getScore() * 100) . '%)';
        }, $filteredObjects));
    }

    private function pushoverNotification(string $message, string $imageData): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://api.pushover.net/1/messages.json",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                "token"      => $_ENV['PUSHOVER_APP_TOKEN'],
                "user"       => $_ENV['PUSHOVER_USER_KEY'],
                "message"    => $message,
                "attachment" => curl_file_create('data://application/octet-stream;base64,' . base64_encode($imageData), 'image.jpg', 'image/jpeg'),
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}

$processor = new VisionApiProcessor();
$processor->processImage();