<?php
use DTApi\Repository\UserRepository;
use DTApi\Models\User;

class UserRepositoryTest extends TestCase
{
    // Initialize the UserRepository instance for testing
    protected $userRepository;

    protected function setUp()
    {
        parent::setUp();
        $this->userRepository = new UserRepository(new User);
    }

    // Test the createOrUpdate method
    public function testCreateOrUpdate()
    {
        $userData = [
            'role' => 'translator',
            'name' => 'John Doe',
        ];

        // Call the createOrUpdate method
        $result = $this->userRepository->createOrUpdate(null, $userData);

        // Assert the result based on your expectations
        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals('John Doe', $result->name);
    }
}