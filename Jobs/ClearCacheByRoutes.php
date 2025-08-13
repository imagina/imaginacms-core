<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Iwebhooks\Entities\Log;

class ClearCacheByRoutes implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  public $urls;
  public $entity;

  /**
   * Create a new job instance.
   */
  public function __construct($entity = null, $urls = [])
  {
    $this->entity = $entity;

    if (isset($this->entity->id))
      $this->urls = $this->initCacheClearableData('urls');

    !is_array($urls) ? $urls = [$urls] : false;
    $this->urls = array_merge($this->urls ?? [], $urls);
  }

  /**
   * Execute the job.
   */
  public function handle()
  {
    $client = new \GuzzleHttp\Client();
    $domain = preg_replace("(^https?://)", "", config("app.url"));

    if (!empty($this->urls)) {
      foreach ($this->urls as $url) {
        try {
          \Log::info('CACHE::RUNING ' . $url);
          $promise = $client->get($url, [
            'headers' => ['icache-bypass' => 1],
            'curl' => [CURLOPT_RESOLVE => ["$domain:80:127.0.0.1"]]
          ]);
          \Log::info('CACHE::DONE ' . $url);
        }catch (\Exception $e){
          \Log::info('CACHE::FAILED' . ($url ?? 'no url'). ' --> ' . $e->getMessage());
        }

      }
    }
  }

  /**
   * Return the needed data by cache provider from model
   *
   * @param $type
   * @return mixed|null
   */
  public function initCacheClearableData($type)
  {
    $response = null;
    if (method_exists($this->entity, 'getCacheClearableData')) {
      $cacheClearableData = $this->entity->getCacheClearableData();
      $response = $cacheClearableData[$type] ?? [];
      $baseUrl = config("app.url");
      //Move the base url to the end (home is expencive to load time)
      if (($baseUrlKey = array_search($baseUrl, $response)) !== false) {
        unset($response[$baseUrlKey]);
        $response[] = $baseUrl;
      }
    }
    return $response;
  }
}
