/*
 * Copyright (C) eZ Systems AS. All rights reserved.
 * For full copyright and license information view LICENSE file distributed with this source code.
 */

(function (global, doc) {
    var eZ = global.eZ = global.eZ || {};

    /**
     * YooChoose recommender REST client.
     *
     * @class
     * @param {Object} config user settings
     */
    eZ.RecommendationRestClient = function (config) {
        this.endpointUrl = config.endpointUrl || '';
        this.feedbackUrl = config.feedbackUrl || '';
        this.attributes = config.attributes || [];
        this.scenario = config.scenario || '';
        this.limit = config.limit || 0;
        this.language = config.language || '';
        this.attributes = config.attributes || [];
        this.contentType = config.contentType || '';
        this.outputType = config.outputType || '';
        this.contextItems = config.contextItems || '';
        this.categoryPath = config.categoryPath || '';
        this.errorMessage = config.errorMessage || 'Error occurred while loading recommendations';
        this.notSupportedMessage = config.notSupportedMessage || 'Cannot display recommendations, this browser is not supported';
        this.unauthorizedMessage = config.unauthorizedMessage || 'Unauthorized access';
    };

    /**
     * Requests recommendations from recommender engine.
     *
     * @param {RecommendationRestClient~onSuccess} responseCallback
     * @param {RecommendationRestClient~onFailure} errorCallback
     */
    eZ.RecommendationRestClient.prototype.fetchRecommendations = function (responseCallback, errorCallback) {
        var xmlhttp = eZ.RecommendationRestClient.getXMLHttpRequest();

        if (xmlhttp === null) {
            errorCallback(this.notSupportedMessage);

            return;
        }

        xmlhttp.onreadystatechange = function () {
            var jsonResponse;

            if (xmlhttp.readyState === 4) {
                if (xmlhttp.status === 200) {
                    jsonResponse = JSON.parse(xmlhttp.response);
                    responseCallback(jsonResponse.recommendationResponseList, this);
                } else if (xmlhttp.status == 401) {
                    errorCallback(this.unauthorizedMessage);
                } else {
                    errorCallback(this.errorMessage);
                }
            }
        };

        var attributes = '';
        for (var i = 0; i < this.attributes.length; i++) {
            attributes = attributes + '&attribute=' + this.attributes[i];
        }

        xmlhttp.open('GET', this.endpointUrl + this.scenario + '.json' + '?numrecs=' + this.limit + '&' + 'contextitems=' + this.contextItems + attributes + '&contenttype=' + this.contentType + '&outputtype=' + this.outputType + '&categorypath=' + encodeURIComponent(this.categoryPath) + '&lang=' + this.language, true);
        xmlhttp.send();
    };

    /**
     * Sends notification ping.
     *
     * @static
     * @method ping
     * @param {String} url
     */
    eZ.RecommendationRestClient.ping = function (url) {
        var xmlhttp = eZ.RecommendationRestClient.getXMLHttpRequest();

        if (xmlhttp === null) {
            return true;
        }

        xmlhttp.open('GET', url, false);
        xmlhttp.send();

        return true;
    };

    /**
     * Returns available XMLHttpRequest object (depending on the browser).
     *
     * @static
     * @method getXMLHttpRequest
     * @returns {Object} XMLHttpRequest
     */
    eZ.RecommendationRestClient.getXMLHttpRequest = function () {
        var xmlHttp;

        if (global.XMLHttpRequest) {
            xmlHttp = new XMLHttpRequest();
        } else {
            try {
                xmlHttp = new ActiveXObject('Msxml2.XMLHTTP');
            } catch(e) {
                try {
                    xmlHttp = new ActiveXObject('Microsoft.XMLHTTP');
                } catch(e) {
                    xmlHttp = null;
                }
            }
        }

        return xmlHttp;
    };

})(window, document);
