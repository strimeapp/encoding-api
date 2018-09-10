<?php

    namespace StrimeEncodingAPI\GlobalBundle\Entity;

    use Doctrine\ORM\Mapping as ORM;

    /**
     * @ORM\Entity
 	 * @ORM\HasLifecycleCallbacks
     * @ORM\Table(name="encoding_sessions")
     */
    class Sessions
    {
        /**
         * @ORM\Id
         * @ORM\Column(type="binary")
         */
        protected $sess_id;

        /**
         * @ORM\Column(name="sess_data", type="blob", nullable=false)
         */
        protected $sess_data;

        /**
         * @ORM\Column(name="sess_time", type="integer", nullable=false)
         */
        protected $sess_time;

        /**
         * @ORM\Column(name="sess_lifetime", type="integer", nullable=false)
         */
        protected $sess_lifetime;



        /**
         * @param binary $sess_id
         * @return Sessions
         */
        public function setSessId($sess_id) {
            $this->sess_id = $sess_id;
            return $this;
        }

        /**
         * @return binary
         */
        public function getSessId() {
            return $this->sess_id;
        }

        /**
         * @param blob $sess_data
         * @return Sessions
         */
        public function setSessData($sess_data) {
            $this->sess_data = $sess_data;
            return $this;
        }

        /**
         * @return blob
         */
        public function getSessData() {
            return $this->sess_data;
        }

        /**
         * @param integer $sess_time
         * @return Sessions
         */
        public function setSessTime($sess_time) {
            $this->sess_time = $sess_time;
            return $this;
        }

        /**
         * @return integer
         */
        public function getSessTime() {
            return $this->sess_time;
        }

        /**
         * @param integer $sess_lifetime
         * @return Sessions
         */
        public function setSessLifetime($sess_lifetime) {
            $this->sess_lifetime = $sess_lifetime;
            return $this;
        }

        /**
         * @return integer
         */
        public function getSessLifetime() {
            return $this->sess_lifetime;
        }
    }