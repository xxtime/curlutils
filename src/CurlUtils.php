<?php

namespace Xxtime\CurlUtils;


/**
 * CurlUtils for spider
 */
class CurlUtils
{

    // curl multi thread
    protected $thread = 5;

    // default curl options
    protected $options = [
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => null,
        CURLOPT_COOKIEJAR      => null,
        CURLOPT_HTTPHEADER     => ['accept-language: en-US,en;q=0.8', 'Cookie: locale=en_US'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_1 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13B143 Safari/601.1',
    ];

    // curl output info
    protected $info = [
        'size_download' => 0,
        'task_unique'   => 0,
        'task_total'    => 0,
        'task_success'  => 0,
        'task_fail'     => 0,
        'time_total'    => 0,
    ];

    // task pool
    protected $taskPool = [];

    // task Set
    protected $taskSet = [];

    // running task number
    protected $running = null;

    // curl multi handle
    protected $mh;


    /**
     * set thread number
     * @param int $number
     */
    public function setThread($number = 0)
    {
        $this->thread = $number;
    }


    /**
     * set curl default options
     * @param array $options
     * @param null $type
     * @return bool
     */
    public function setOptions($options = [], $type = null)
    {
        if (!is_array($options)) {
            return false;
        }
        foreach ($options as $opt => $value) {
            if (is_int($opt)) {
                $this->options[$opt] = $value;
            }
            elseif (defined($opt)) {
                $this->options[constant($opt)] = $value;
            }
        }
    }


    /**
     * @param array $urls
     * @param array $options
     * @param null $callback [$class, 'function']
     */
    public function addTask($urls = [], $options = [], $callback = null)
    {
        if (!is_array($urls)) {
            $urls = [$urls];
        }

        foreach ($urls as $url) {
            $hash = md5($url);
            if (array_key_exists($hash, $this->taskSet)) {
                $this->info['task_success'] += 1;
                continue;
            }
            $this->taskSet[$hash] = [
                'callback' => $callback
            ];

            $this->taskPool[$hash] = [
                'url'      => $url,
                'options'  => $options,
                'callback' => $callback,
            ];
        }

        $this->info['task_total'] += count($urls);
    }


    /**
     * curl multi
     * @return bool
     */
    public function curlMultiRun()
    {
        $startTime = microtime(true);

        $this->mh = curl_multi_init();

        $this->checkTask();

        // $this->running && $mrc == CURLM_OK
        while ($this->running) {
            if (curl_multi_select($this->mh) == -1) {
                usleep(100);
            }

            $this->curlMultiExec();

            while (($info = curl_multi_info_read($this->mh)) != false) {
                if ($info["result"] == CURLE_OK) {

                    // stats download size
                    $i = curl_getinfo($info['handle']);
                    $this->info['size_download'] += $i['size_download'];

                    // get content and close handle
                    $output = curl_multi_getcontent($info['handle']);
                    curl_multi_remove_handle($this->mh, $info['handle']);
                    curl_close($info['handle']);

                    // callback
                    $callback = $this->taskSet[md5($i['url'])]['callback'];
                    if (is_callable($callback)) {
                        call_user_func_array($callback, [$output]);
                    }

                    $this->info['task_success'] += 1;
                }

                if ($info["result"] != CURLE_OK) {
                    $this->info['task_fail'] += 1;
                }

                $this->checkTask();
            }
        }

        curl_multi_close($this->mh);

        $this->info['time_total'] = microtime(true) - $startTime;
    }


    /**
     * curl function
     * @param string $url
     * @param string $post
     * @param array $options
     * @return mixed
     */
    public function curl($url = '', $post = '', $options = [])
    {
        if (!$options) {
            $options = &$this->options;
        }
        $ch = $this->curlInit($url, $options, $post);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }


    /**
     * get task info
     * @return array
     */
    public function getInfo()
    {
        $this->info['task_unique'] = count($this->taskSet);
        return $this->info;
    }


    /**
     * check and add task into pool
     */
    protected function checkTask()
    {
        if (!$this->taskPool) {
            return false;
        }

        $count = min($this->thread - $this->running, count($this->taskPool));

        while ($count > 0) {
            $task = array_shift($this->taskPool);
            if (!$task['options']) {
                $task['options'] = &$this->options;
            }
            curl_multi_add_handle($this->mh, $this->curlInit($task['url'], $task['options']));

            $count--;
        }

        $this->curlMultiExec();
    }


    /**
     * curl_multi_exec
     */
    protected function curlMultiExec()
    {
        // echo '|';
        do {
            $mrc = curl_multi_exec($this->mh, $this->running);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }


    /**
     * curl init
     * @param string $url
     * @param array $options
     * @param null $post
     * @return resource
     */
    protected function curlInit($url = '', $options = [], $post = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, str_replace(' ', '+', trim($url)));
        curl_setopt_array($ch, $options);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        return $ch;
    }

}