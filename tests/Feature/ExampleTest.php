<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_redirects_to_backoffice(): void
    {
        // routes/web.php redireciona '/' para '/backoffice' por desenho — não é um bug.
        $response = $this->get('/');

        $response->assertRedirect('/backoffice');
    }
}
