<?php

class HelperFunctionTest extends TestCase
{
    public function testWillExpireAt()
    {
        $dueTime = '2023-11-06 10:00:00';
        $createdAt = '2023-11-06 08:00:00';

        $result = \DTApi\Helpers\TeHelper::willExpireAt($dueTime, $createdAt);

        // Assert that the result matches your expected output
        $this->assertEquals('2023-11-06 11:30:00', $result);
    }
}