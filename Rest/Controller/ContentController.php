<?php

/**
 * This file is part of the EzSystemsRecommendationBundle package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace EzSystems\RecommendationBundle\Rest\Controller;

use eZ\Bundle\EzPublishCoreBundle\Imagine\AliasGenerator as ImageVariationService;
use eZ\Publish\API\Repository\Values\Content\Field;
use eZ\Publish\API\Repository\Values\ContentType\ContentType;
use eZ\Publish\Core\MVC\ConfigResolverInterface;
use eZ\Publish\Core\REST\Server\Controller as BaseController;
use eZ\Publish\Core\Repository\ContentService;
use eZ\Publish\Core\Repository\LocationService;
use eZ\Publish\Core\Repository\ContentTypeService;
use eZ\Publish\Core\Repository\SearchService;
use EzSystems\RecommendationBundle\Rest\Values\ContentData as ContentDataValue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UrlGenerator;

/**
 * Recommendation REST Content controller.
 */
class ContentController extends BaseController
{
    /** @var \eZ\Publish\Core\MVC\ConfigResolverInterface */
    protected $configResolver;

    /** @var \eZ\Bundle\EzPublishCoreBundle\Imagine\AliasGenerator */
    protected $imageVariationService;

    /** @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface */
    protected $generator;

    /** @var \eZ\Publish\Core\Repository\ContentService */
    protected $contentService;

    /** @var \eZ\Publish\Core\Repository\LocationService */
    protected $locationService;

    /** @var \eZ\Publish\Core\Repository\ContentTypeService */
    protected $contentTypeService;

    /** @var \eZ\Publish\Core\Repository\SearchService */
    protected $searchService;

    /**
     * @param \eZ\Publish\Core\MVC\ConfigResolverInterface $configResolver
     * @param \eZ\Bundle\EzPublishCoreBundle\Imagine\AliasGenerator $imageVariationService
     * @param \Symfony\Component\Routing\Generator\UrlGeneratorInterface $generator
     * @param \eZ\Publish\Core\Repository\ContentService $contentService
     * @param \eZ\Publish\Core\Repository\LocationService $locationService
     * @param \eZ\Publish\Core\Repository\ContentTypeService $contentTypeService
     * @param \eZ\Publish\Core\Repository\SearchService $searchService
     */
    public function __construct(
        ConfigResolverInterface $configResolver,
        ImageVariationService $imageVariationService,
        UrlGenerator $generator,
        ContentService $contentService,
        LocationService $locationService,
        ContentTypeService $contentTypeService,
        SearchService $searchService
    ) {
        $this->configResolver = $configResolver;
        $this->imageVariationService = $imageVariationService;
        $this->generator = $generator;
        $this->contentService = $contentService;
        $this->locationService = $locationService;
        $this->contentTypeService = $contentTypeService;
        $this->searchService = $searchService;
    }

    /**
     * Prepares content for ContentDataValue class.
     *
     * @param string $contentIdList
     *
     * @return \EzSystems\RecommendationBundle\Rest\Values\ContentData
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundExceptionif the content, version with the given id and languages or content type does not exist
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the user has no access to read content and in case of un-published content: read versions
     */
    public function getContent($contentIdList)
    {
        $contentIds = explode(',', $contentIdList);
        $content = $this->prepareContent($contentIds);

        return new ContentDataValue($content);
    }

    /**
     * Prepare content array.
     *
     * @param array $contentIds
     *
     * @return array
     */
    protected function prepareContent($contentIds)
    {
        $requestLanguage = $this->request->get('lang');
        $requestedFields = $this->request->get('fields');

        $content = array();

        foreach ($contentIds as $contentId) {
            $contentValue = $this->contentService->loadContent($contentId, array($requestLanguage));
            $contentType = $this->contentTypeService->loadContentType($contentValue->contentInfo->contentTypeId);
            $location = $this->locationService->loadLocation($contentValue->contentInfo->mainLocationId);
            $language = (null === $requestLanguage) ? $contentType->mainLanguageCode : $requestLanguage;

            $content[$contentId] = array(
                'contentId' => $contentId,
                'contentTypeId' => $contentType->id,
                'identifier' => $contentType->identifier,
                'language' => $language,
                'publishedDate' => $contentValue->contentInfo->publishedDate->format('c'),
                'author' => $contentValue->getFieldValue('author'),
                'uri' => $this->generator->generate($location, array(), false),
                'mainLocation' => array(
                    'href' => '/api/ezp/v2/content/locations' . $location->pathString,
                ),
                'locations' => array(
                    'href' => '/api/ezp/v2/content/objects/' . $contentId . '/locations',
                ),
                'categoryPath' => $location->pathString,
                'fields' => array(),
            );

            $fields = $this->prepareFields($contentType, $requestedFields);
            $imageFieldIdentifier = $this->getImageFieldIdentifier($contentId, $language);

            if (!empty($fields)) {
                foreach ($fields as $field) {
                    $fieldValue = $contentValue->getFieldValue($field, $language);

                    if ($relatedField = $this->getRelation($contentValue, $language)) {
                        $fieldValue = $this->getRelatedFieldValue((string) $relatedField, $language, $imageFieldIdentifier);
                        $field = $imageFieldIdentifier;
                    } elseif ($field == $imageFieldIdentifier) {
                        $fieldObj = $contentValue->getFieldsByLanguage($language);
                        $fieldValue = $this->imageVariations($fieldObj[$field], $contentValue->versionInfo, $this->request->get('image'));
                    }

                    if (null === $fieldValue) {
                        continue;
                    }

                    $content[$contentId]['fields'][] = array(
                        'key' => $field,
                        'value' => (string) $fieldValue,
                    );
                }
            }
        }

        return $content;
    }

    /**
     * Checks if fields are given, if not - returns all of them.
     *
     * @param string $fields
     * @param \eZ\Publish\API\Repository\Values\ContentType\ContentType $contentType
     *
     * @return array|null
     */
    protected function prepareFields(ContentType $contentType, $fields = null)
    {
        if (null !== $fields) {
            return explode(',', $fields);
        }

        $fields = array();
        $contentFields = $contentType->getFieldDefinitions();

        foreach ($contentFields as $field) {
            $fields[] = $field->identifier;
        }

        return $fields;
    }

    /**
     * Returns image uri based on variation provided in url.
     * If none is set original is returned.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Field $fieldValue
     * @param \eZ\Publish\Core\Repository\Values\Content\VersionInfo $versionInfo
     * @param string|null $variation
     *
     * @return string
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidVariationException
     * @throws \eZ\Publish\Core\MVC\Exception\SourceImageNotFoundException
     */
    private function imageVariations(Field $fieldValue, $versionInfo, $variation = null)
    {
        if (!isset($fieldValue->value->id)) {
            return '';
        }

        $variations = $this->configResolver->getParameter('image_variations');

        if ((null === $variation) || !in_array($variation, array_keys($variations))) {
            $variation = 'original';
        }

        return $this->imageVariationService->getVariation($fieldValue, $versionInfo, $variation)->uri;
    }

    /**
     * Return uri of the related image field.
     *
     * @param mixed $contentId
     * @param string $language
     * @param string $imageFieldIdentifier
     *
     * @return string
     */
    private function getRelatedFieldValue($contentId, $language, $imageFieldIdentifier)
    {
        $content = $this->contentService->loadContent($contentId);
        $fieldObj = $content->getFieldsByLanguage($language);

        if (!isset($fieldObj[$imageFieldIdentifier])) {
            return '';
        }

        return $this->imageVariations($fieldObj[$imageFieldIdentifier], $content->versionInfo, $this->request->get('image'));
    }

    /**
     * Return identifier of a field of ezimage type.
     *
     * @param mixed $contentId
     * @param string $language
     *
     * @return string
     */
    private function getImageFieldIdentifier($contentId, $language)
    {
        $content = $this->contentService->loadContent($contentId, array($language));
        $contentType = $this->contentTypeService->loadContentType($content->contentInfo->contentTypeId);

        foreach ($contentType->fieldDefinitions as $fieldDefinition) {
            if ($fieldDefinition->fieldTypeIdentifier == 'ezimage') {
                return $fieldDefinition->identifier;
            } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezobjectrelation') {
                $field = $content->getFieldValue($fieldDefinition->identifier, $language);

                return $this->getImageFieldIdentifier($field->destinationContentId, $language);
            }
        }

        return false;
    }

    /**
     * Checks if content has image relation field, returns its ID if true.
     *
     * @param \eZ\Publish\Core\Repository\Values\Content\Content $content
     * @param string $language
     */
    private function getRelation($content, $language)
    {
        $contentType = $this->contentTypeService->loadContentType($content->contentInfo->contentTypeId);

        foreach ($contentType->fieldDefinitions as $fieldDefinition) {
            if ($fieldDefinition->fieldTypeIdentifier == 'ezobjectrelation') {
                $fieldValue = $content->getFieldValue($fieldDefinition->identifier, $language);

                return $fieldValue->destinationContentId;
            }
        }

        return false;
    }
}
