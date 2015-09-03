<?php

/**
 * This file is part of the EzSystemsRecommendationBundle package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace EzSystems\RecommendationBundle\Rest\Controller;

use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use EzSystems\RecommendationBundle\Rest\Values\ContentData as ContentDataValue;

/**
 * Recommendation REST ContentType controller.
 */
class ContentTypeController extends ContentController
{
    /**
     * Prepares content for ContentDataValue class.
     *
     * @param string $contentTypeIdList
     *
     * @return \EzSystems\RecommendationBundle\Rest\Values\ContentData ContentDataValue
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if the content, version with the given id and languages or content type does not exist
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the user has no access to read content and in case of un-published content: read versions
     */
    public function getContentType($contentTypeIdList)
    {
        $contentTypeIds = explode(',', $contentTypeIdList);
        $content = $this->prepareContentByContentTypeIds($contentTypeIds);

        return new ContentDataValue($content);
    }

    /**
     * Returns paged content based on ContentType ids.
     *
     * @param array $contentTypeIds
     *
     * @return array
     */
    protected function prepareContentByContentTypeIds($contentTypeIds)
    {
        $pageSize = $this->request->get('page_size', 10);
        $page = $this->request->get('page', 1);
        $offset = $page * $pageSize - $pageSize;
        $path = $this->request->get('path');
        $hidden = $this->request->get('hidden');

        $criteria = array(new Criterion\ContentTypeId($contentTypeIds));

        if ($path) {
            $criteria[] = new Criterion\Subtree($path);
        }
        if (!$hidden) {
            $criteria[] = new Criterion\Visibility(Criterion\Visibility::VISIBLE);
        }

        $query = new Query();
        $query->query = new Criterion\LogicalAnd($criteria);

        $query->limit = $pageSize;
        $query->offset = $offset;

        $contentItems = $this->searchService->findContent($query)->searchHits;

        return $this->prepareContent($contentItems);
    }
}
