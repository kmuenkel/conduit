<?php

namespace Conduit\Tests\Feature;

use Conduit\Tests\BaseTestCase;
use Conduit\Testing\TestDriver;
use Conduit\Tests\SetUp\DummyAdapter;

/**
 * Class AdapterTest
 * @package Tests\Feature
 */
class AdapterTest extends BaseTestCase
{
    /**
     * @var DummyAdapter
     */
    protected $dummyAdapter;
    
    /**
     * @void
     */
    public function setup()
    {
        parent::setup();
        
        $driver = app(TestDriver::class);
        $this->dummyAdapter = app(DummyAdapter::class)->setDriver($driver);
    }
    
    /**
     * @test
     */
    public function canFakeResponses()
    {
//        $this->appendResponse([
//            'uri.host' => 'api.html2pdfrocket.com',
//            'uri.path' => '/pdf',
//            'uri.method' => 'POST'
//        ], $sampleContent);
        
        ddd($this->dummyAdapter->dummyEndpoint());
        
        self::assertTrue(true);
    }
}
