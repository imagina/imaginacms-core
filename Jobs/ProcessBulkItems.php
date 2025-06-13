<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

use Request;

class ProcessBulkItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * vars
     */
    private $log;

    public $modelClass;
    public $chunkId;
    public $partition;
    public $items;

    /**
     * Construct Base
     */
    public function __construct($modelClass,$chunkId,$partition,array $items)
    {
        $this->log = 'Core: Jobs||ProcessBulkItems|';

        $this->modelClass = $modelClass;
        $this->chunkId = $chunkId;
        $this->partition = $partition;
        $this->items = $items;
    }

    /**
     * Execute the job.
     */
    public function handle(Request $request)
    {

        \Log::info($this->log."chunkId: ".$this->chunkId."||INIT");

        //Not clear cache
        app()->instance('clearResponseCache', false);

        $msjs = [];  //Final Msjs to Errors
        $itemsCompleted = []; //Items Completed
        $this->createOrUpdateItems($msjs,$itemsCompleted);

        //Process to send final notification
        $this->webhookProcess($msjs,$itemsCompleted);

        //Apply ClearAllResponseCache (JOB) and clean the home
        $this->initProcessCache();

        \Log::info($this->log."chunkId: ".$this->chunkId."||END");

    }

    /**
     * Create or Update with msjs
     */
    private function createOrUpdateItems(&$msjs,&$itemsCompleted)
    {
        \Log::info($this->log."createOrUpdateItems");

        //Inst Model
        $model = new $this->modelClass;
        $repository = app($model->repository);
        $transformer = $model->transformer;

        //Check all Items
        foreach ($this->items as $key => $item)
        {
            //Check if is creating or updating
            $operation = isset($item['id']) ? 'update' : 'create';
            try {
                $itemResult = ($operation == 'create') ? $repository->create($item) : $repository->updateBy($item['id'],$item);

                //items Save
                $itemsCompleted[] = new $transformer($itemResult);

            } catch (\Exception $e) {
                $msjs[] = [
                  'type' => "error",
                  'operation' => $operation,
                  'msjs' =>  $e->getFile() . " -> " . $e->getLine()." -> ".$e->getMessage(),
                  'item' => $item
                ];
            }
        }

    }

    /**
     * Webook Process
     * @param $msjs (Error msjs from item)
     */
    private function webhookProcess($msjs,$itemsCompleted)
    {

        \Log::info($this->log."Webhook Process");

        $totalItems = count($this->items);
        $failed = count($msjs);

        //Response Final
        $dataToResponse = [
            'chunckId' => $this->chunkId,
            'partition' => $this->partition,
            'totalItems' => $totalItems,
            'completed' => $totalItems-$failed,
            'itemsCompleted' => $itemsCompleted,
            'errors' => $failed,
            'msjs' => $msjs
        ];

        //\Log::info($this->log."Webhook Process|DataToResponse: ".json_encode($dataToResponse));

        $eventName = 'custom.bulk '.$this->modelClass;
        event($eventName, [$dataToResponse]);

    }

    /**
     * Init Process Cache | Final JOB
     */
    private function initProcessCache()
    {

        \Log::info($this->log."initProcessCache");

        //JOB Clear
        \Modules\Core\Jobs\ClearAllResponseCache::dispatch(['force' => true]);

        //Clean Homepage
        try{
            $url = url('/');
            $client = new \GuzzleHttp\Client();
            $promise = $client->get($url, ['headers' => ['icache-bypass' => 1]]);
            \Log::info('Route Update Cache: '. $url);
        }catch(\Exception $e){
            \Log::error($this->log."initProcessCache|msj: ".$e->getMessage());
        }

    }

}
