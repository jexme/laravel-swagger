<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

if (!function_exists('strip_optional_char')) {
    function strip_optional_char($uri)
    {
        return str_replace('?', '', $uri);
    }
}

if (!function_exists('get_annotations')) {
    /**
     * Get annotations from text filtering by name.
     *
     * @param string $from  The annotation name with "@". E.g.: "@throws"
     * @param string $annotationName
     * @return array
     */
    function get_annotations(string $from, string $annotationName): array
    {
        preg_match_all('#@(.*?)\n#s', $from, $annotations);

        $foundAnnotations = [];
        foreach (reset($annotations) as $annotation) {
            if (Str::startsWith($annotation, $annotationName)) {
                $foundAnnotations[] = trim(Str::replaceFirst($annotationName, '', $annotation));
            }
        }

        return $foundAnnotations;
    }
}

if (!function_exists('get_all_model_relations')) {
    /**
     * Identify all relationships for a given model
     *
     * @todo Create unit test fot this method.
     * @param Model $model Model
     * @param string $heritage A flag that indicates whether parent and/or child
     *                         relationships should be included
     * @return array
     * @throws ReflectionException
     */
    function get_all_model_relations(Model $model = null, $heritage = 'all') {
        $modelName = get_class($model);
        $types = ['children' => 'Has', 'parents' => 'Belongs', 'all' => ''];
        $heritage = in_array($heritage, array_keys($types)) ? $heritage : 'all';

        $reflectionClass = new ReflectionClass($model);
        $traits = $reflectionClass->getTraits();
        $traitMethodNames = [];
        foreach ($traits as $name => $trait) {
            $traitMethods = $trait->getMethods();
            foreach ($traitMethods as $traitMethod) {
                $traitMethodNames[] = $traitMethod->getName();
            }
        }

        // Checking the return value actually requires executing the method.
        // So use this to avoid infinite recursion.
        $currentMethod = collect(explode('::', __METHOD__))->last();
        $filter = $types[$heritage];
        // The method must be public
        $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

        $methods = collect($methods)
            ->filter(
                function (ReflectionMethod $method) use (
                    $modelName,
                    $traitMethodNames,
                    $currentMethod
                ) {
                    $methodName = $method->getName();
                    /**
                     * - The method must not originate in a trait
                     * - It must not be a magic method
                     * - It must be in the self scope and not inherited
                     * - It must be in the this scope and not static
                     * - It must not be an override of this one
                     */
                    if (!in_array($methodName, $traitMethodNames)
                        && strpos($methodName, '__') !== 0
                        && $method->class === $modelName
                        && !$method->isStatic()
                        && $methodName != $currentMethod
                    ) {
                        $parameters = (
                            new ReflectionMethod($modelName, $methodName)
                        )->getParameters();
                        // If required parameters exist, this will be false and
                        // omit this method
                        return collect($parameters)->filter(
                            function (ReflectionParameter $parameter) {
                                // The method must have no required parameters
                                return !$parameter->isOptional();
                            }
                        )->isEmpty();
                    }
                    return false;
                }
            )
            ->map(function (ReflectionMethod $method) use ($model, $filter) {
                $methodName = $method->getName();
                /** @var Relation|mixed $relation */
                $relation = $model->$methodName();
                // Must return a Relation child. This is why we only want to do
                // this once
                if (is_subclass_of($relation, Relation::class)) {
                    $type = (new ReflectionClass($relation))->getShortName();
                    // If relation is of the desired heritage
                    if (!$filter || strpos($type, $filter) === 0) {
                        return [
                            'method' => $methodName,
                            'related_model' => $relation->getRelated(),
                            'relation' => get_class($relation),
                        ];
                    }
                }
                return null;
            })
            // Remove elements reflecting methods that do not have the desired
            // return type
            ->filter()
            ->toArray();

        return $methods;
    }
}