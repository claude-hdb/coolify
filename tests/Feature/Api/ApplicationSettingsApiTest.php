<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'application-settings-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = $this->project->environments()->first();
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function applicationSettingsApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('GET /api/v1/applications/{uuid}/settings', function () {
    test('returns the full settings record without internal metadata', function () {
        $this->application->settings->update([
            'is_static' => true,
            'is_auto_deploy_enabled' => false,
        ]);

        $response = $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/settings")
            ->assertOk()
            ->assertJsonPath('is_static', true)
            ->assertJsonPath('is_auto_deploy_enabled', false)
            ->assertJsonMissingPath('id')
            ->assertJsonMissingPath('application_id')
            ->assertJsonMissingPath('created_at')
            ->assertJsonMissingPath('updated_at');

        expect($response->json())->toHaveKeys([
            'is_force_https_enabled',
            'is_debug_enabled',
            'is_preview_deployments_enabled',
            'is_build_server_enabled',
            'is_gpu_enabled',
        ]);
    });

    test('returns 404 for applications owned by another team', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        $otherDestination = StandaloneDocker::withoutEvents(function () use ($otherServer) {
            return StandaloneDocker::forceCreate([
                'uuid' => (string) new Cuid2,
                'name' => 'other-test-docker',
                'network' => 'coolify-other',
                'server_id' => $otherServer->id,
            ]);
        });

        $otherProject = Project::create([
            'uuid' => (string) new Cuid2,
            'name' => 'other-test-project',
            'team_id' => $otherTeam->id,
        ]);
        $otherApplication = Application::factory()->create([
            'environment_id' => $otherProject->environments()->first()->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
        ]);

        $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$otherApplication->uuid}/settings")
            ->assertNotFound();
    });

    test('returns 404 for an unknown application uuid', function () {
        $this->withHeaders(applicationSettingsApiHeaders($this->bearerToken))
            ->getJson('/api/v1/applications/unknown-application-uuid/settings')
            ->assertNotFound();
    });

    test('rejects unauthenticated requests', function () {
        $this->getJson("/api/v1/applications/{$this->application->uuid}/settings")
            ->assertUnauthorized();
    });
});
