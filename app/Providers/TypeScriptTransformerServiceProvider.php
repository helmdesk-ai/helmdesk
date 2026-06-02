<?php

namespace App\Providers;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeImmutable;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;

/**
 * 配置后端 Data 和枚举生成前端 TypeScript 类型。
 */
class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    /**
     * 设置类型扫描目录、输出文件和通用类型替换。
     */
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(AttributedClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path())
            ->outputDirectory(resource_path('js/types'))
            ->writer(new FlatModuleWriter('generated.d.ts'))
            ->replaceType(DateTime::class, 'string')
            ->replaceType(DateTimeImmutable::class, 'string')
            ->replaceType(CarbonInterface::class, 'string')
            ->replaceType(CarbonImmutable::class, 'string')
            ->replaceType(Carbon::class, 'string');
    }
}
