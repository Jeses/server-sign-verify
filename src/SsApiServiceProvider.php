<?php namespace Zhengcai\SsApi;


use Illuminate\Support\ServiceProvider;

class SsApiServiceProvider extends ServiceProvider
{

    /**
     * @var bool
     */
    protected $defer = false;


    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/ssapi.php' => config_path('ssapi.php'),
        ]);
    }

    /**
     * @return void
     */
    public function register()
    {
        $this->app->singleton('SsApi', function () {
            return new SsApiService();
        }
        );
    }

    /**
     * @return array
     */
    public function provides()
    {
        return ['SsApi'];
    }

}
