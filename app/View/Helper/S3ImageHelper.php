<?php

App::uses('AppHelper', 'View/Helper');

class S3ImageHelper extends AppHelper
{
    public $helpers = array('Html');

    public function link($key)
    {
        App::import('Component', 'S3');
        $s3component = new S3Component(new ComponentCollection());
        $s3 = $s3component->getClient();

        $bucket_name = Configure::read('bucket_name');
        $url = $s3->getObjectUrl($bucket_name, $key, "30 minutes");

        return $url;
    }

    public function thumbnail($key)
    {
        $bucket_name = Configure::read('thumbnail_bucket_name');

        return "https://s3-". Configure::read('region').".amazonaws.com/". $bucket_name . "/" . $key;
    }
}
