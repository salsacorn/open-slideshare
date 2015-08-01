<?php
use Aws\S3\S3Client;
use Aws\Common\InstanceMetadata\InstanceMetadataClient;

class S3Component extends Component
{
    public function getClient()
    {
        if (isset($_SERVER["AWS_ACCESS_ID"]) && isset($_SERVER['AWS_SECRET_KEY'])) {
            $config = array(
                'key' => $_SERVER["AWS_ACCESS_ID"],
                'secret' => $_SERVER["AWS_SECRET_KEY"],
            );
            $s3 = S3Client::factory($config);
        } else {
            $s3 = S3Client::factory();
        }
        return $s3;
    }

    public function resize($data)
    {
        // S3 object key
        $key = $data['key'];
        // post record id
        $id = $data['id'];
        // filename to use for original one from S3
        $file_path = tempnam(TMP, "original");
        // filename for resized one
        $dest_file_path = tempnam(TMP, "resized");

        // retrieve original file from S3
        $s3 = $this->getClient();
        $object = $s3->getObject(array(
            "Bucket" => Configure::read('bucket_name'),
            "Key" => $key,
            'SaveAs' => $file_path
        ));

        // decide file type
        $type = @exif_imagetype($file_path);
        if ($type == IMAGETYPE_GIF) {
            $func = "gif";
        } elseif ($type == IMAGETYPE_JPEG) {
            $func = "jpeg";
        } elseif ($type == IMAGETYPE_PNG) {
            $func = "png";
        } else {
            return false;
        }

        // Specify what kind of PHP function will you use.
        $create_func = "imagecreatefrom{$func}";
        $out_func = "image{$func}";
        $img = $create_func($file_path);

        // get size
        $width = ImageSx($img);
        $height = ImageSy($img);

        // get resized size
        $dst_width = 300;
        $dst_height = ceil(300 * $height / max($width, 1));

        // generate file
        $out = ImageCreateTrueColor($dst_width, $dst_height);
        ImageCopyResampled($out, $img,
            0,0,0,0, $dst_width, $dst_height, $width, $height);
        $out_func($out, $dest_file_path);

        // store thumbnail to S3
        $s3->putObject(array(
            'Bucket'       => Configure::read('thumbnail_bucket_name'),
            'Key'          => $key,
            'SourceFile'   => $dest_file_path,
            'ContentType'  => image_type_to_mime_type($type),
            'ACL'          => 'public-read',
            'StorageClass' => 'REDUCED_REDUNDANCY',
        ));

        // update the db record
        $post = ClassRegistry::init('Post');
        if ($post->exists($id)) {
            $post->read(null, $id);
            $post->set('thumb_generated', true);
            $post->save();
        }

        return true;
    }

    private function print_logs($logs)
    {
        foreach ($logs as $log) {
            $this->print_log($log);
        }
    }

    private function print_log($log)
    {
        echo "[LOG] " . $log . "\n";
    }

    private function list_local_images($dir)
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir,
                FilesystemIterator::CURRENT_AS_FILEINFO |
                FilesystemIterator::KEY_AS_PATHNAME |
                FilesystemIterator::SKIP_DOTS
            )
        );

        $files = new RegexIterator($files, '/^.+\.jpg$/i', RecursiveRegexIterator::MATCH);
        return $files;
    }

    public function extract_images($data)
    {
        // S3 object key
        $key = $data['key'];
        // post record id
        $id = $data['id'];
        // filename to use for original one from S3
        $save_dir = TMP . $key;
        @mkdir($save_dir);
        $file_path = tempnam($save_dir, "original");

        // retrieve original file from S3
        $this->print_log("Start retrieving file from S3");
        $s3 = $this->getClient();
        $object = $s3->getObject(array(
            "Bucket" => Configure::read('bucket_name'),
            "Key" => $key,
            'SaveAs' => $file_path
        ));

        $mime_type = $this->get_mime_type($file_path);
        $this->print_log("File Type is ". $mime_type);

        // Convertable mime type
        $all_convertable = array(
            "application/pdf",
            "application/vnd.openxmlformats-officedocument.presentationml.presentation",
            "application/vnd.ms-powerpoint",
        );
        $need_to_convert_pdf = array(
            "application/vnd.openxmlformats-officedocument.presentationml.presentation",
            "application/vnd.ms-powerpoint",
        );

        try {
            // Convert PowerPoint to PDF
            if (in_array($mime_type, $need_to_convert_pdf)) {
                $status = $this->convert_ppt_to_pdf($file_path);
                if (!$status) {
                    $this->update_status($key, ERROR_CONVERT_PPT_TO_PDF);
                    return false;
                }
            } elseif (in_array($mime_type, $all_convertable)) {
                $this->print_log("Renaming file...");
                rename($file_path, $file_path.".pdf");
            }

            if (in_array($mime_type, $all_convertable)) {
                // Convert PDF to ppm
                $status = $this->convert_pdf_to_ppm($save_dir, $file_path);
                if (!$status) {
                    $this->update_status($key, ERROR_CONVERT_PDF_TO_PPM);
                    return false;
                }

                // Convert ppm to jpg
                $status = $this->convert_ppm_to_jpg($save_dir);
                if (!$status) {
                    $this->update_status($key, ERROR_CONVERT_PPM_TO_JPG);
                    return false;
                }

                $files = $this->list_local_images($save_dir);
                $first_page = false;
                $this->upload_extract_images($key, $save_dir, $files, $first_page);

                // create thumbnail images
                if ($first_page) {
                    $this->create_thumbnail($key, $first_page);
                }
                $this->print_log("Converting file successfully completed!!");
                // update the db record
                $this->update_status($key, SUCCESS_CONVERT_COMPLETED);
            } else {
                $this->update_status($key, ERROR_NO_CONVERT_SOURCE);
                $this->print_log("No Convertable File");
            }
        } catch(Exception $e) {
            $this->update_status($key, -99);
        } finally {
            $this->print_log("Cleaning up working directory " . $save_dir . "...");
            $this->cleanup_working_dir($save_dir);
        }
        $this->print_log("Completed to run the process...");

        return true;
    }

    /**
     * Convert PPT file to PDF
     *
     * @param string $file_path source file to convert
     *
     */
    public function convert_ppt_to_pdf($file_path)
    {
        $status = "";
        $command_logs = array();

        $this->print_log("Start converting PowerPoint to PDF");
        exec("unoconv -f pdf -o " . $file_path . ".pdf ". $file_path, $command_logs, $status);
        $this->print_logs($command_logs);
        if ($status != 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Convert PDF file to PPM
     *
     * @param string $save_dir path to store file
     *        string $file_path source file to convert
     *
     */
    public function convert_pdf_to_ppm($save_dir, $file_path)
    {
        $status = "";
        $command_logs = array();

        $this->print_log("Start converting PDF to ppm");
        exec("cd ". $save_dir . "&& pdftoppm " . $file_path . ".pdf slide", $command_logs, $status);
        $this->print_logs($command_logs);
        if ($status != 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Convert PPM file to Jpeg
     *
     * @param string $save_dir path to store file
     *
     */
    public function convert_ppm_to_jpg($save_dir)
    {
        $status = "";
        $command_logs = array();

        $this->print_log("Start converting ppm to jpg");
        exec("cd ". $save_dir . "&& mogrify -format jpg slide*.ppm", $command_logs, $status);
        $this->print_logs($command_logs);
        if ($status != 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get mime type from file
     *
     * @param string $file_path path to file
     * @return string mime_type
     */
    public function get_mime_type($file_path)
    {
        $mime = shell_exec('file -bi '.escapeshellcmd($file_path));
        $mime = trim($mime);
        $parts = explode(";", $mime);
        $mime = preg_replace("/ [^ ]*/", "", trim($parts[0]));
        return $mime;
    }

    public function upload_extract_images($key, $save_dir, $files, &$first_page)
    {
        $s3 = $this->getClient();
        $file_array = array();

        foreach ($files as $file_path => $file_info) {
            $file_key = str_replace(TMP, '', $file_path);
            $file_array[] = $file_key;
            // store image to S3
            $this->print_log("Start uploading image to S3. ".$file_key);
            $s3->putObject(array(
                'Bucket'       => Configure::read('image_bucket_name'),
                'Key'          => $file_key,
                'SourceFile'   => $file_path,
                'ContentType'  => "image/jpg",
                'ACL'          => 'public-read',
                'StorageClass' => 'REDUCED_REDUNDANCY',
            ));
        }

        sort($file_array);
        $json_contents = json_encode($file_array, JSON_UNESCAPED_SLASHES);
        file_put_contents($save_dir."/list.json", $json_contents);

        // store list.json to S3
        $this->print_log("Start uploading list.json to S3");
        $s3->putObject(array(
            'Bucket'       => Configure::read('image_bucket_name'),
            'Key'          => $key . "/list.json",
            'SourceFile'   => $save_dir . "/list.json",
            'ContentType'  => "text/plain",
            'ACL'          => 'public-read',
            'StorageClass' => 'REDUCED_REDUNDANCY',
        ));

        if (count($file_array) > 0) {
            $first_page = $file_array[0];
        } else {
            $first_page = false;
        }
    }

    /**
     * Delete slide from S3
     *
     * @param  string $key key to remove
     * @return void
     */
    public function delete_slide_from_s3($key)
    {
        $this->delete_master_slide($key);
        $this->delete_generated_files($key);
    }

    public function delete_master_slide($key)
    {
        $s3 = $this->getClient();
        $res = $s3->deleteObject(array(
            "Bucket" => Configure::read('bucket_name'),
            "Key" => $key
        ));
    }

    public function delete_generated_files($key)
    {
        $s3 = $this->getClient();
        // List files and delete them.
        $res = $s3->listObjects(array('Bucket' => Configure::read('image_bucket_name'), 'MaxKeys' => 1000, 'Prefix' => $key . '/'));
        $keys = $res->getPath('Contents');
        $delete_files = array();
        if(is_array($keys))
        {
            foreach ($keys as $kk) {
                $delete_files[] = array("Key" => $kk["Key"]);
            }
        }
        if(count($delete_files) > 0)
        {
            $res = $s3->deleteObjects(array(
                'Bucket'  => Configure::read('image_bucket_name'),
                'Objects' => $delete_files
            ));
        }
    }

    public function cleanup_working_dir($dir)
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $command = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $command($fileinfo->getRealPath());
        }
        rmdir($dir);
    }

    public function create_thumbnail($key, $filename)
    {
        // Create Same Size Thumbnail
        $f = TMP . $filename;
        $src_image = imagecreatefromjpeg($f);

        // get size
        $width = ImageSx($src_image);
        $height = ImageSy($src_image);

        // Tatenaga...
        if ($height > $width * 0.75) {
            $src_y = (int) ($height - ($width * 0.75));
            $src_h = $height - $src_y;
            $src_x = 0;
            $src_w = $width;
        } else {
            // Yokonaga
            $src_y = 0;
            $src_h = $height;
            $src_x = 0;
            $src_w = $height / 0.75;
        }

        // get resized size
        $dst_w = 320;
        $dst_h = 240;

        // generate file
        $dst_image = ImageCreateTrueColor($dst_w, $dst_h);
        ImageCopyResampled($dst_image, $src_image, 0, 0, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);

        imagejpeg($dst_image, TMP .$key.  "/thumbnail.jpg");

        // store thumbnail to S3
        $s3 = $this->getClient();
        $s3->putObject(array(
            'Bucket'       => Configure::read('image_bucket_name'),
            'Key'          => $key . "/thumbnail.jpg",
            'SourceFile'   => TMP . $key . "/thumbnail.jpg",
            'ContentType'  => "image/jpeg",
            'ACL'          => 'public-read',
            'StorageClass' => 'REDUCED_REDUNDANCY',
        ));
    }

    /**
     * Update status code in Slide to indicate conversion status
     *
     * @param string $key
     *        int    $status_code
     * @return void
     *
     */
    private function update_status($key, $status_code)
    {
        $slide = ClassRegistry::init('Slide');
        $slide->primaryKey = "key";
        if ($slide->exists($key)) {
            $slide->read(null, $key);
            $slide->set('convert_status', $status_code);
            $slide->save();
        }
    }

    public function getSigningKey($shortDate, $region, $service, $secretKey)
    {
        $dateKey = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
        $signinKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);

        return $signinKey;
    }

    public function createPolicy($id_to_redirect)
    {
        App::uses('CommonHelper', 'View/Helper');
        $this->Common = new CommonHelper(new View());

        date_default_timezone_set("UTC");
        $date_ymd = gmdate("Ymd");
        $date_gm = gmdate("Ymd\THis\Z");
        $acl = "public-read";
        $expires = gmdate("Y-m-d\TH:i:s\Z", time() + 60 * 120);
        $success_action_redirect = $this->Common->base_url() . "/attachments/complete/" . $id_to_redirect;

        // will be replaced from Env var or IAM Role
        if (isset($_SERVER["AWS_ACCESS_ID"]) && isset($_SERVER['AWS_SECRET_KEY'])) {
            $access_id = $_SERVER["AWS_ACCESS_ID"];
            $secret_key = $_SERVER["AWS_SECRET_KEY"];
            $security_token = "";
        } else {
            $meta_client = InstanceMetadataClient::factory();
            $credentials = $meta_client->getInstanceProfileCredentials();
            $access_id = $credentials->getAccessKeyId();
            $secret_key = $credentials->getSecretKey();
            $security_token = $credentials->getSecurityToken();
        }

        //---------------------------------------------
        // 1. Create a policy using UTF-8 encoding.
        // This includes custom meta data named "x-amz-meta-title" for example
        //---------------------------------------------
        $p_array = array(
          "expiration" => $expires,
          "conditions" => array(
            array("bucket" => Configure::read('bucket_name')),
            array("starts-with", '$key', ""),
            array("acl" => $acl),
            // array("success_action_redirect" => $success_action_redirect),
            array("success_action_status" => '201'),
            array("starts-with", '$Content-Type', "application/octetstream"),
            array("x-amz-meta-uuid" => "14365123651274"),
            array("starts-with", '$x-amz-meta-tag', ""),
            array("x-amz-credential" => $access_id."/".$date_ymd."/".Configure::read('region')."/s3/aws4_request"),
            array("x-amz-algorithm" => "AWS4-HMAC-SHA256"),
            array("x-amz-date" => $date_gm),
            array("starts-with", '$x-amz-meta-title', ""),  // Custom Field
          ),
        );

        if ($security_token != "") {
            $p_array["conditions"][] = array("x-amz-security-token" => $security_token);
        }

        $policy = (json_encode($p_array, JSON_UNESCAPED_SLASHES));

        //---------------------------------------------
        // 2. Convert the UTF-8-encoded policy to Base64. The result is the string to sign.
        //---------------------------------------------
        $base64_policy = base64_encode($policy);
        $base64_policy = str_replace(array("\r\n", "\r", "\n", " "), "", $base64_policy);

        //---------------------------------------------
        // 3. Create the signature as an HMAC-SHA256 hash of the string to sign. You will provide the signing key as key to the hash function.
        //---------------------------------------------
        // https://github.com/aws/aws-sdk-php/blob/00c4d18d666d2da44814daca48deb33e20cc4d3c/src/Aws/Common/Signature/SignatureV4.php
        $signinkey = $this->getSigningKey($date_ymd, Configure::read('region'), "s3", $secret_key);
        $signature = hash_hmac('sha256', $base64_policy, $signinkey, false);

        $result = array(
            'access_id'     => $access_id,
            'base64_policy' => $base64_policy,
            'date_ymd'      => $date_ymd,
            'date_gm'       => $date_gm,
            'acl'           => $acl,
            'security_token'=> $security_token,
            'signature'     => $signature,
            // 'success_action_redirect' => $success_action_redirect,
            'success_action_status' => '201',
        );

        return $result;
    }

    /**
     * Download original file from bucket
     *
     * @param string $key filename in S3
     *
     */
    function get_original_file_download_path($key)
    {
        $s3 = $this->getClient();
        $url = $s3->getObjectUrl(Configure::read('bucket_name'), $key, '+15 minutes');
        return $url;
    }
}
