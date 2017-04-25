<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4 foldmethod=marker: */
// +---------------------------------------------------------------------------
// | SWAN [ $_SWANBR_SLOGAN_$ ]
// +---------------------------------------------------------------------------
// | Copyright $_SWANBR_COPYRIGHT_$
// +---------------------------------------------------------------------------
// | Version  $_SWANBR_VERSION_$
// +---------------------------------------------------------------------------
// | Licensed ( $_SWANBR_LICENSED_URL_$ )
// +---------------------------------------------------------------------------
// | $_SWANBR_WEB_DOMAIN_$
// +---------------------------------------------------------------------------

namespace Kafka\Protocol;

/**
+------------------------------------------------------------------------------
* Kafka protocol for consumer fetch api 
+------------------------------------------------------------------------------
*
* @package
* @version $_SWANBR_VERSION_$
* @copyright Copyleft
* @author $_SWANBR_AUTHOR_$
+------------------------------------------------------------------------------
*/

class Fetch extends Protocol
{
    // {{{ functions
    // {{{ public function encode()

    /**
     * consumer fetch request encode
     *
     * @param array $payloads
     * @access public
     * @return string
     */
    public function encode($payloads)
    {
        if (!isset($payloads['data'])) {
            throw new \Kafka\Exception\Protocol('given fetch kafka data invalid. `data` is undefined.');
        }

        if (!isset($payloads['replica_id'])) {
            $payloads['replica_id'] = -1;
        }

        if (!isset($payloads['max_wait_time'])) {
            $payloads['max_wait_time'] = 100; // default timeout 100ms
        }

        if (!isset($payloads['min_bytes'])) {
            $payloads['min_bytes'] = 64 * 1024; // 64k
        }

        $header = $this->requestHeader('kafka-php', self::FETCH_REQUEST, self::FETCH_REQUEST);
        $data   = self::pack(self::BIT_B32, $payloads['replica_id']);
        $data  .= self::pack(self::BIT_B32, $payloads['max_wait_time']);
        $data  .= self::pack(self::BIT_B32, $payloads['min_bytes']);
        $data  .= self::encodeArray($payloads['data'], array(__CLASS__, '_encodeFetchTopic'));
        $data   = self::encodeString($header . $data, self::PACK_INT32);

        return $data;
    }

    // }}}
    // {{{ public function decode()

    /**
     * decode fetch response
     *
     * @access public
     * @return array
     */
    public function decode($data)
    {
        $offset = 0;
        $version = $this->getApiVersion(self::FETCH_REQUEST);
        $throttleTime = 0;
        if ($version != self::API_VERSION0) {
            $throttleTime = self::unpack(self::BIT_B32, substr($data, $offset, 4));
            $offset += 4;
        }

        $topics = $this->decodeArray(substr($data, $offset), array($this, 'fetchTopic'), $version);
        $offset += $topics['length'];

        return array(
            'throttleTime' => $throttleTime,
            'topics' => $topics['data'],
        );
    }

    // }}}
    // {{{ protected function fetchTopic()

    /**
     * decode fetch topic response
     *
     * @access protected
     * @return array
     */
    protected function fetchTopic($data, $version)
    {
        $offset = 0;
        $topicInfo = $this->decodeString(substr($data, $offset), self::BIT_B16);
        $offset += $topicInfo['length'];

        $partitions = $this->decodeArray(substr($data, $offset), array($this, 'fetchPartition'), $version);
        $offset += $partitions['length'];

        return array(
            'length' => $offset,
            'data' => array(
                'topicName' => $topicInfo['data'],
                'partitions'  => $partitions['data'],
            )
        );
    }

    // }}}
    // {{{ protected function fetchPartition()

    /**
     * decode fetch partition response
     *
     * @access protected
     * @return array
     */
    protected function fetchPartition($data, $version)
    {
        $offset = 0;
        $partitionId = self::unpack(self::BIT_B32, substr($data, $offset, 4));
        $offset += 4;
        $errorCode = self::unpack(self::BIT_B16_SIGNED, substr($data, $offset, 2));
        $offset += 2;
        $highwaterMarkOffset = self::unpack(self::BIT_B64, substr($data, $offset, 8));
        $offset += 8;

        $messageSetSize = self::unpack(self::BIT_B32, substr($data, $offset, 4));
        $offset += 4;

        if ($offset < strlen($data) && $messageSetSize) {
            $messages = $this->decodeMessageSetArray(substr($data, $offset, $messageSetSize), array($this, 'decodeMessageSet'));
            $offset += $messages['length'];
        }

        return array(
            'length' => $offset,
            'data' => array(
                'partition' => $partitionId,
                'errorCode' => $errorCode,
                'highwaterMarkOffset' => $highwaterMarkOffset,
                'messageSetSize' => $messageSetSize,
                'messages' => isset($messages['data']) ? $messages['data'] : array(),
            )
        );
    }

    // }}}
    // {{{ public function decodeMessageSetArray()

    /**
     * decode message Set
     *
     * @param array $array
     * @param Callable $func
     * @param null $options
     * @return string
     * @access public
     */
    public function decodeMessageSetArray($data, $func, $options = null)
    {
        $offset = 0;
        if (!is_callable($func, false)) {
            throw new \Kafka\Exception\Protocol('Decode array failed, given function is not callable.');
        }

        $result = array();
        while ($offset < strlen($data)) {
            $value = substr($data, $offset);
            if (!is_null($options)) {
                $ret = call_user_func($func, $value, $options);
            } else {
                $ret = call_user_func($func, $value);
            }

            if (!is_array($ret) && $ret === false) {
                break;
            }

            if (!isset($ret['length']) || !isset($ret['data'])) {
                throw new \Kafka\Exception\Protocol('Decode array failed, given function return format is invliad');
            }
            if ($ret['length'] == 0) {
                continue;
            }

            $offset += $ret['length'];
            $result[] = $ret['data'];
        }

        return array('length' => $offset, 'data' => $result);
    }

    // }}}
    // {{{ public function decodeMessageSet()

    /**
     * decode message set
     * N.B., MessageSets are not preceded by an int32 like other array elements
     * in the protocol.
     *
     * @param array $messages
     * @param int $compression
     * @return string
     * @access public
     */
    protected function decodeMessageSet($data)
    {
        if (strlen($data) <= 12) {
            return false;
        }
        $offset = 0;
        $roffset = self::unpack(self::BIT_B64, substr($data, $offset, 8));
        $offset += 8;
        $messageSize = self::unpack(self::BIT_B32, substr($data, $offset, 4));
        $offset += 4;
        $ret = $this->decodeMessage(substr($data, $offset), $messageSize);
        if (!is_array($ret) && $ret == false) {
            return false;
        }
        $offset += $ret['length'];

        return array(
            'length' => $offset,
            'data' => array(
                'offset' => $roffset,
                'size'   => $messageSize,
                'message' => $ret['data'],
            )
        );
    }

    // }}}
    // {{{ public function decodeMessage()

    /**
     * decode message
     * N.B., MessageSets are not preceded by an int32 like other array elements
     * in the protocol.
     *
     * @param array $messages
     * @param int $compression
     * @return string
     * @access public
     */
    protected function decodeMessage($data, $messageSize)
    {
        if (strlen($data) < $messageSize || !$messageSize) {
            return false;
        }

        $offset = 0;
        $crc = self::unpack(self::BIT_B32, substr($data, $offset, 4));
        $offset += 4;
        $magic = self::unpack(self::BIT_B8, substr($data, $offset, 1));
        $offset += 1;
        $attr  = self::unpack(self::BIT_B8, substr($data, $offset, 1));
        $offset += 1;
        $timestamp = 0;
        $version = $this->getApiVersion(self::FETCH_REQUEST);
        if ($version == self::API_VERSION2) {
            $timestamp = self::unpack(self::BIT_B64, substr($data, $offset, 8));
            $offset += 8;
        }
        $keyRet = $this->decodeString(substr($data, $offset), self::BIT_B32);
        $offset += $keyRet['length'];
        $valueRet = $this->decodeString(substr($data, $offset), self::BIT_B32);
        $offset += $valueRet['length'];

        return array(
            'length' => $offset,
            'data'   => array(
                'crc' => $crc,
                'magic' => $magic,
                'attr' => $attr,
                'timestamp' => $timestamp,
                'key' => $keyRet['data'],
                'value' => $valueRet['data'],
            )
        );
    }

    // }}}
    // {{{ protected static function _encodeFetchPartion()

    /**
     * encode signal part
     *
     * @param partions
     * @static
     * @access protected
     * @return string
     */
    protected static function _encodeFetchPartion($values)
    {
        if (!isset($values['partition_id'])) {
            throw new \Kafka\Exception\Protocol('given fetch data invalid. `partition_id` is undefined.');
        }

        if (!isset($values['offset'])) {
            $values['offset'] = 0;
        }

        if (!isset($values['max_bytes'])) {
            $values['max_bytes'] = 2 * 1024 * 1024;
        }

        $data = self::pack(self::BIT_B32, $values['partition_id']);
        $data .= self::pack(self::BIT_B64, $values['offset']);
        $data .= self::pack(self::BIT_B32, $values['max_bytes']);

        return $data;
    }

    // }}}
    // {{{ protected static function _encodeFetchTopic()

    /**
     * encode signal topic
     *
     * @param partions
     * @static
     * @access protected
     * @return string
     */
    protected static function _encodeFetchTopic($values)
    {
        if (!isset($values['topic_name'])) {
            throw new \Kafka\Exception\Protocol('given fetch data invalid. `topic_name` is undefined.');
        }

        if (!isset($values['partitions']) || empty($values['partitions'])) {
            throw new \Kafka\Exception\Protocol('given fetch data invalid. `partitions` is undefined.');
        }

        $topic = self::encodeString($values['topic_name'], self::PACK_INT16);
        $partitions = self::encodeArray($values['partitions'], array(__CLASS__, '_encodeFetchPartion'));

        return $topic . $partitions;
    }

    // }}}
    // }}}
}
