<?php

class TrackRow extends Pix_Table_Row
{
    public function isTrackBy($user)
    {
        return TrackUser::search(array(
            'track_id' => $this->id,
            'user_id' => intval($user->user_id),
        ))->count();
    }

    public function needTrack()
    {
        // 最近修改過的話直接去抓不用管 tracked_at
        if ($this->updated_at > $this->tracked_at) {
            return true;
        }
        if (0 == $this->track_period) { // 每日
            $time = 86400;
        } elseif (1 == $this->track_period) { // 每五分鐘
            $time = 300;
        } elseif (2 == $this->track_period) { // 每六小時
            $time = 6 * 3600;
        }

        return time() > $this->tracked_at + $time;
    }

    public function getLatestLog()
    {
        return TrackLog::search(array('track_id' => $this->id))->max('time');
    }

    public function updateLog($content)
    {
        if ($content != $this->getLatestLog()->content) {
            $log = TrackLog::search(array('track_id' => $this->id, 'content' => $content))->order('time DESC')->first();

            TrackLog::insert(array(
                'track_id' => $this->id,
                'time' => time(),
                'content' => $content,
            ));

            return array($content, $log->time);
        }
        return false;
    }

    public function getTrackContent()
    {
        $options = json_decode($this->options);
        return $options->track_content;
    }

    public function getWay()
    {
        $options = json_decode($this->options);
        return $options->track_way;
    }

    public function getHTML()
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($curl, CURLOPT_URL, $this->url);
        $content = curl_exec($curl);
        if (preg_match('#charset=big5#i', $content)) {
          $content = iconv('big5', 'utf-8//IGNORE', $content);
        }
        $info = curl_getinfo($curl);
        return array(
            'http_code' => $info['http_code'],
            'content' => $content,
        );
    }

    public function trackContent()
    {
        switch ($this->getWay()) {
        case 2: // 追蹤 HTML + regex
            $obj = $this->getHTML();
            if (!preg_match_all($this->getTrackContent(), $obj['content'], $matches)) {
                return array(
                    'http_code' => $obj['http_code'],
                    'status' => 'notfound',
                );
            }

            array_shift($matches);
            return array(
                'http_code' => $obj['http_code'],
                'status' => 'found',
                'data' => $matches,
            );
        case 3: // 檔案 MD5
            $curl = curl_init();
            $download_fp = tmpfile();
            curl_setopt($curl, CURLOPT_URL, $this->url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($curl, CURLOPT_FILE, $download_fp);
            curl_exec($curl);
            $info = curl_getinfo($curl);
            curl_close($curl);
            fflush($download_fp);

            $filepath = stream_get_meta_data($download_fp)['uri'];
            $ret = array(
                'http_code' => $info['http_code'],
                'status' => filesize($filepath) ? 'success' : 'failed',
                'md5' => md5_file($filepath), 
                'size' => filesize($filepath),
            );
            if ($this->getTrackContent()) {
                $ret['content'] = file_get_contents($filepath);
            }
            return $ret;
        }
    }
}

class Track extends Pix_Table
{
    public function init()
    {
        $this->_name = 'track';
        $this->_primary = 'id';
        $this->_rowClass = 'TrackRow';

        $this->_columns['id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['title'] = array('type' => 'text');
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['updated_at'] = array('type' => 'int');
        $this->_columns['tracked_at'] = array('type' => 'int');
        $this->_columns['url'] = array('type' => 'varchar', 'size' => 255);
        $this->_columns['options'] = array('type' => 'text');
        // 0-每日, 1-每五分鐘
        $this->_columns['track_period'] = array('type' => 'int');
    }

    public static function getTrackPeriods()
    {
        return array(
            0 => '每日',
            1 => '每五分鐘',
            2 => '每六小時',
        );
    }

    public static function getTrackWays()
    {
        return array(
            1 => '純文字 regex 判斷',
            2 => 'HTML regex 判斷',
            3 => '檔案內容',
        );
    }

    public static function updateTrack()
    {
        $user_logs = array();

        foreach (Track::search(1) as $track) {
            if (!$track->needTrack()) {
                continue;
            }
            $track->update(array(
                'tracked_at' => time(),
            ));
            $log = $track->updateLog(json_encode($track->trackContent()));
            if (false === $log) {
                continue;
            }
            foreach (TrackUser::search(array('track_id' => $track->id)) as $track_user) {
                if (!array_key_exists($track_user->user_id, $user_logs)) {
                    $user_logs[$track_user->user_id] = array();
                }
                $user_logs[$track_user->user_id][] = array(
                    'track' => $track,
                    'content' => $log[0],
                    'last_hit' => $log[1],
                );
            }
        }

        foreach ($user_logs as $user_id => $logs) {
            $title = 'ContentTrack 發現網頁變動 ' . count($logs) . ' 筆';
            $content = '';
            if (!$user = User::find(intval($user_id))) {
                continue;
            }
            $mail = substr($user->user_name, 9);
            foreach ($logs as $log) {
                $content .= "標題: {$log['track']->title}\n";
                $content .= "原始網址: {$log['track']->url}\n";
                $content .= "紀錄網址: http://" . getenv('CONTENTTRACK_DOMAIN') . "/?id={$log['track']->id}#track-logs\n";
                if ($log['last_hit']) {
                    $content .= "與 " . date('Y/m/d H:i:s', $log['last_hit']) . " 內容相同\n";
                }
                $log['content'] = json_encode(json_decode($log['content']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $content .= "內容: {$log['content']}\n";
                $content .= "==============================================\n";
            }

            error_log('mail to: ' . $mail);
            NotifyLib::alert(
                $title,
                $content,
                $mail
            );
        }
    }
}
