<?php

class KurogoError
{
	public $code;
	public $title;
	public $message;
	
	public function __toString() {
	    return $this->message;
	}
	
	public function __construct($code, $title, $message) {
        $this->setCode($code);
        $this->setTitle($title);
        $this->setMessage($message);
    }

    /**
     * returns whether the given value is an error object
     * @param mixed $data variable to check
     * @return boolean returns true if the value is an error object
     */
    public static function isError($data) {
        return $data instanceOf KurogoError;
    }    

    /**
     * returns error message
     * @return string
     */
	public function getMessage() {
		return $this->message;
	}

    /**
     * sets error message
     * @param string $message
     */
	public function setMessage($message) {
		$this->message = $message;
	}

    /**
     * sets error code
     * @param int $code
     */
 	public function setCode($code) {
		$this->code = intval($code);
	}

    /**
     * sets error data
     * @param mixed $userinfo
     */
	public function setTitle($title) {
		$this->title = $title;
	}

    /**
     * returns error code
     * @return int
     */
	public function getCode() {
		return $this->code;
	}

    /**
     * returns error data
     * @return mixed
     */
    public function getTitle() {
        return $this->title;
    }
    
    public static function errorFromException(Exception $exception) {
        $error = new KurogoError($exception->getCode(), 'Exception', $exception->getMessage());
        if(!Kurogo::getSiteVar('PRODUCTION_ERROR_HANDLER_ENABLED')) {
            $error->file = $exception->getFile();
            $error->line = $exception->getLine();
            $error->trace = $exception->getTrace();
        }
        return $error;
    }
}
