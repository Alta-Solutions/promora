> For clean Markdown of any page, append .md to the page URL.
> For a complete documentation index, see https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/inventory/locations/llms.txt.
> For full documentation content, see https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/inventory/locations/llms-full.txt.

# Create Locations

POST https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations
Content-Type: application/json

Create new locations.

**Limits**
* Limit of 50 concurrent requests.
* Limit of 100 active locations.


Reference: https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/inventory/locations/create-locations

## OpenAPI Specification

```yaml
openapi: 3.1.0
info:
  title: management-inventory
  version: 1.0.0
paths:
  /inventory/locations:
    post:
      operationId: create-locations
      summary: Create Locations
      description: |
        Create new locations.

        **Limits**
        * Limit of 50 concurrent requests.
        * Limit of 100 active locations.
      tags:
        - subpackage_locations
      parameters:
        - name: X-Auth-Token
          in: header
          description: >-
            ### OAuth scopes


            | UI Name | Permission | Parameter |

            |:--------|:-----------|:----------|

            |  Store Inventory | read-only | `store_inventory_read_only` |

            |  Store Inventory | modify | `store_inventory` |


            ### Authentication header


            | Header | Argument | Description |

            |:-------|:---------|:------------|

            | `X-Auth-Token` | `access_token` | For more about API accounts that
            generate `access_token`s, see [API Accounts and OAuth
            Scopes](/developer/docs/overview/api-fundamentals/api-accounts#api-accounts).
            |


            ### Further reading


            For example requests and more information about authenticating
            BigCommerce APIs, see [Authentication and Example
            Requests](/developer/docs/overview/api-fundamentals/api-accounts).


            For more about BigCommerce OAuth scopes, see [API Accounts and OAuth
            Scopes](/developer/docs/overview/api-fundamentals/api-accounts#oauth-scopes).


            For a list of API status codes, see [API Status
            Codes](/developer/api-reference/rest/overview#rest-http-status-codes).
          required: true
          schema:
            type: string
      responses:
        '200':
          description: Locations have been successfully created.
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/SimpleTransactionResponse'
        '422':
          description: >
            Incorrect entity. Locations were not valid. This results from
            missing required fields, invalid data, or partial error. See the
            response for more details.
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
      requestBody:
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/LocationsCreateRequest'
servers:
  - url: https://api.bigcommerce.com/stores/store_hash/v3
components:
  schemas:
    LocationsCreateRequestItemsTypeId:
      type: string
      enum:
        - PHYSICAL
        - VIRTUAL
      default: PHYSICAL
      description: Describe type of given location.
      title: LocationsCreateRequestItemsTypeId
    OperatingHoursForDay:
      type: object
      properties:
        open:
          type: boolean
          default: false
          description: Boolean variable that defines if the location is open or not.
        opening:
          type: string
          format: string
          description: Opening time.
        closing:
          type: string
          format: string
          description: Closing time.
      title: OperatingHoursForDay
    OperatingHours:
      type: object
      properties:
        sunday:
          $ref: '#/components/schemas/OperatingHoursForDay'
        monday:
          $ref: '#/components/schemas/OperatingHoursForDay'
        tuesday:
          $ref: '#/components/schemas/OperatingHoursForDay'
        wednesday:
          $ref: '#/components/schemas/OperatingHoursForDay'
        thursday:
          $ref: '#/components/schemas/OperatingHoursForDay'
        friday:
          $ref: '#/components/schemas/OperatingHoursForDay'
        saturday:
          $ref: '#/components/schemas/OperatingHoursForDay'
      description: Schedule with opening and closing hours for each day of the week.
      title: OperatingHours
    LocationsCreateRequestItemsAddressGeoCoordinates:
      type: object
      properties:
        latitude:
          type: number
          format: double
          description: Latitude.
        longitude:
          type: number
          format: double
          description: Longitude.
      required:
        - latitude
        - longitude
      description: Object with latitude and longitude that points at the location.
      title: LocationsCreateRequestItemsAddressGeoCoordinates
    LocationsCreateRequestItemsAddress:
      type: object
      properties:
        address1:
          type: string
          description: Main address information.
        address2:
          type: string
          description: Additional address information.
        city:
          type: string
          description: The city where the location is located.
        state:
          type: string
          format: enum
          description: The state where the location is located.
        zip:
          type: string
          description: Zip code of the location.
        email:
          type: string
          format: email
          description: Email of the location.
        phone:
          type: string
          description: Phone number of the location.
        geo_coordinates:
          $ref: >-
            #/components/schemas/LocationsCreateRequestItemsAddressGeoCoordinates
          description: Object with latitude and longitude that points at the location.
        country_code:
          type: string
          format: enum
          description: ISO 3166-1 alpha-3 code.
      required:
        - address1
        - city
        - state
        - zip
        - email
        - geo_coordinates
        - country_code
      description: Address is required if the locationʼs `type_id` is `PHYSICAL`.
      title: LocationsCreateRequestItemsAddress
    BlackoutHoursItems:
      type: object
      properties:
        label:
          type: string
        date:
          type: string
          format: date
        open:
          type: boolean
        opening:
          type: string
          format: string
          default: '00:00'
        closing:
          type: string
          format: string
          default: '00:00'
        all_day:
          type: boolean
          default: false
        annual:
          type: boolean
          default: false
      required:
        - label
        - date
        - open
      title: BlackoutHoursItems
    BlackoutHours:
      type: array
      items:
        $ref: '#/components/schemas/BlackoutHoursItems'
      title: BlackoutHours
    LocationsCreateRequestItems:
      type: object
      properties:
        code:
          type: string
          description: Location user-defined unique identifier.
        label:
          type: string
          description: Location label.
        description:
          type: string
          description: >-
            Description of location. This field can be returned by the GraphQL
            Storefront API so that additional details can be exposed to
            customers on the storefront (customer-facing). 
        managed_by_external_source:
          type: boolean
          default: false
          description: >
            Indicates if the third-party system is the source of truth for
            inventory values. If set to true, manually editing inventory in the
            BigCommerce control panel will be disabled.
        type_id:
          $ref: '#/components/schemas/LocationsCreateRequestItemsTypeId'
          description: Describe type of given location.
        enabled:
          type: boolean
          default: true
        operating_hours:
          $ref: '#/components/schemas/OperatingHours'
        time_zone:
          type: string
          description: >-
            Time zone of location. For a list of valid time zones, please view:
            https://en.wikipedia.org/wiki/List_of_tz_database_time_zones.
        address:
          $ref: '#/components/schemas/LocationsCreateRequestItemsAddress'
          description: Address is required if the locationʼs `type_id` is `PHYSICAL`.
        storefront_visibility:
          type: boolean
          default: true
        special_hours:
          $ref: '#/components/schemas/BlackoutHours'
      title: LocationsCreateRequestItems
    LocationsCreateRequest:
      type: array
      items:
        $ref: '#/components/schemas/LocationsCreateRequestItems'
      title: LocationsCreateRequest
    SimpleTransactionResponse:
      type: object
      properties:
        transaction_id:
          type: string
          description: Unique identifier of performed action.
      title: SimpleTransactionResponse
    ErrorResponseErrors:
      type: object
      properties: {}
      description: The detailed summary describing the particular error.
      title: ErrorResponseErrors
    ErrorResponse:
      type: object
      properties:
        status:
          type: integer
          description: >-
            The HTTP status code generated by the origin server for this
            occurrence of the problem.
        title:
          type: string
          description: Human readable error message.
        type:
          type: string
          description: >
            URL identifying the error type. Dereferencing the URL leads to
            documentation about the error type.
        errors:
          $ref: '#/components/schemas/ErrorResponseErrors'
          description: The detailed summary describing the particular error.
      title: ErrorResponse
  securitySchemes:
    X-Auth-Token:
      type: apiKey
      in: header
      name: X-Auth-Token
      description: >-
        ### OAuth scopes


        | UI Name | Permission | Parameter |

        |:--------|:-----------|:----------|

        |  Store Inventory | read-only | `store_inventory_read_only` |

        |  Store Inventory | modify | `store_inventory` |


        ### Authentication header


        | Header | Argument | Description |

        |:-------|:---------|:------------|

        | `X-Auth-Token` | `access_token` | For more about API accounts that
        generate `access_token`s, see [API Accounts and OAuth
        Scopes](/developer/docs/overview/api-fundamentals/api-accounts#api-accounts).
        |


        ### Further reading


        For example requests and more information about authenticating
        BigCommerce APIs, see [Authentication and Example
        Requests](/developer/docs/overview/api-fundamentals/api-accounts).


        For more about BigCommerce OAuth scopes, see [API Accounts and OAuth
        Scopes](/developer/docs/overview/api-fundamentals/api-accounts#oauth-scopes).


        For a list of API status codes, see [API Status
        Codes](/developer/api-reference/rest/overview#rest-http-status-codes).

```

## SDK Code Examples

```python
import requests

url = "https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations"

payload = [
    {
        "code": "BIGC-1",
        "label": "Central store",
        "description": "Central shop of the world",
        "managed_by_external_source": False,
        "type_id": "PHYSICAL",
        "enabled": True,
        "operating_hours": {
            "sunday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            },
            "monday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            },
            "tuesday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            },
            "wednesday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            },
            "thursday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            },
            "friday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            },
            "saturday": {
                "open": False,
                "opening": "08:00",
                "closing": "16:00"
            }
        },
        "time_zone": "Etc/UTC",
        "address": {
            "address1": "5th Ave",
            "city": "New York",
            "state": "NY",
            "zip": "10021",
            "email": "test@example.com",
            "geo_coordinates": {
                "latitude": 40.774378,
                "longitude": -73.9653178
            },
            "country_code": "US",
            "address2": "string",
            "phone": "800-555-0198"
        },
        "storefront_visibility": True,
        "special_hours": [
            {
                "label": "Thanksgiving",
                "date": "2022-09-29",
                "open": True,
                "opening": "09:00",
                "closing": "09:00",
                "all_day": False,
                "annual": False
            }
        ]
    }
]
headers = {
    "X-Auth-Token": "<apiKey>",
    "Content-Type": "application/json"
}

response = requests.post(url, json=payload, headers=headers)

print(response.json())
```

```javascript
const url = 'https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations';
const options = {
  method: 'POST',
  headers: {'X-Auth-Token': '<apiKey>', 'Content-Type': 'application/json'},
  body: '[{"code":"BIGC-1","label":"Central store","description":"Central shop of the world","managed_by_external_source":false,"type_id":"PHYSICAL","enabled":true,"operating_hours":{"sunday":{"open":false,"opening":"08:00","closing":"16:00"},"monday":{"open":false,"opening":"08:00","closing":"16:00"},"tuesday":{"open":false,"opening":"08:00","closing":"16:00"},"wednesday":{"open":false,"opening":"08:00","closing":"16:00"},"thursday":{"open":false,"opening":"08:00","closing":"16:00"},"friday":{"open":false,"opening":"08:00","closing":"16:00"},"saturday":{"open":false,"opening":"08:00","closing":"16:00"}},"time_zone":"Etc/UTC","address":{"address1":"5th Ave","city":"New York","state":"NY","zip":"10021","email":"test@example.com","geo_coordinates":{"latitude":40.774378,"longitude":-73.9653178},"country_code":"US","address2":"string","phone":"800-555-0198"},"storefront_visibility":true,"special_hours":[{"label":"Thanksgiving","date":"2022-09-29","open":true,"opening":"09:00","closing":"09:00","all_day":false,"annual":false}]}]'
};

try {
  const response = await fetch(url, options);
  const data = await response.json();
  console.log(data);
} catch (error) {
  console.error(error);
}
```

```go
package main

import (
	"fmt"
	"strings"
	"net/http"
	"io"
)

func main() {

	url := "https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations"

	payload := strings.NewReader("[\n  {\n    \"code\": \"BIGC-1\",\n    \"label\": \"Central store\",\n    \"description\": \"Central shop of the world\",\n    \"managed_by_external_source\": false,\n    \"type_id\": \"PHYSICAL\",\n    \"enabled\": true,\n    \"operating_hours\": {\n      \"sunday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"monday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"tuesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"wednesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"thursday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"friday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"saturday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      }\n    },\n    \"time_zone\": \"Etc/UTC\",\n    \"address\": {\n      \"address1\": \"5th Ave\",\n      \"city\": \"New York\",\n      \"state\": \"NY\",\n      \"zip\": \"10021\",\n      \"email\": \"test@example.com\",\n      \"geo_coordinates\": {\n        \"latitude\": 40.774378,\n        \"longitude\": -73.9653178\n      },\n      \"country_code\": \"US\",\n      \"address2\": \"string\",\n      \"phone\": \"800-555-0198\"\n    },\n    \"storefront_visibility\": true,\n    \"special_hours\": [\n      {\n        \"label\": \"Thanksgiving\",\n        \"date\": \"2022-09-29\",\n        \"open\": true,\n        \"opening\": \"09:00\",\n        \"closing\": \"09:00\",\n        \"all_day\": false,\n        \"annual\": false\n      }\n    ]\n  }\n]")

	req, _ := http.NewRequest("POST", url, payload)

	req.Header.Add("X-Auth-Token", "<apiKey>")
	req.Header.Add("Content-Type", "application/json")

	res, _ := http.DefaultClient.Do(req)

	defer res.Body.Close()
	body, _ := io.ReadAll(res.Body)

	fmt.Println(res)
	fmt.Println(string(body))

}
```

```ruby
require 'uri'
require 'net/http'

url = URI("https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations")

http = Net::HTTP.new(url.host, url.port)
http.use_ssl = true

request = Net::HTTP::Post.new(url)
request["X-Auth-Token"] = '<apiKey>'
request["Content-Type"] = 'application/json'
request.body = "[\n  {\n    \"code\": \"BIGC-1\",\n    \"label\": \"Central store\",\n    \"description\": \"Central shop of the world\",\n    \"managed_by_external_source\": false,\n    \"type_id\": \"PHYSICAL\",\n    \"enabled\": true,\n    \"operating_hours\": {\n      \"sunday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"monday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"tuesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"wednesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"thursday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"friday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"saturday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      }\n    },\n    \"time_zone\": \"Etc/UTC\",\n    \"address\": {\n      \"address1\": \"5th Ave\",\n      \"city\": \"New York\",\n      \"state\": \"NY\",\n      \"zip\": \"10021\",\n      \"email\": \"test@example.com\",\n      \"geo_coordinates\": {\n        \"latitude\": 40.774378,\n        \"longitude\": -73.9653178\n      },\n      \"country_code\": \"US\",\n      \"address2\": \"string\",\n      \"phone\": \"800-555-0198\"\n    },\n    \"storefront_visibility\": true,\n    \"special_hours\": [\n      {\n        \"label\": \"Thanksgiving\",\n        \"date\": \"2022-09-29\",\n        \"open\": true,\n        \"opening\": \"09:00\",\n        \"closing\": \"09:00\",\n        \"all_day\": false,\n        \"annual\": false\n      }\n    ]\n  }\n]"

response = http.request(request)
puts response.read_body
```

```java
import com.mashape.unirest.http.HttpResponse;
import com.mashape.unirest.http.Unirest;

HttpResponse<String> response = Unirest.post("https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations")
  .header("X-Auth-Token", "<apiKey>")
  .header("Content-Type", "application/json")
  .body("[\n  {\n    \"code\": \"BIGC-1\",\n    \"label\": \"Central store\",\n    \"description\": \"Central shop of the world\",\n    \"managed_by_external_source\": false,\n    \"type_id\": \"PHYSICAL\",\n    \"enabled\": true,\n    \"operating_hours\": {\n      \"sunday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"monday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"tuesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"wednesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"thursday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"friday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"saturday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      }\n    },\n    \"time_zone\": \"Etc/UTC\",\n    \"address\": {\n      \"address1\": \"5th Ave\",\n      \"city\": \"New York\",\n      \"state\": \"NY\",\n      \"zip\": \"10021\",\n      \"email\": \"test@example.com\",\n      \"geo_coordinates\": {\n        \"latitude\": 40.774378,\n        \"longitude\": -73.9653178\n      },\n      \"country_code\": \"US\",\n      \"address2\": \"string\",\n      \"phone\": \"800-555-0198\"\n    },\n    \"storefront_visibility\": true,\n    \"special_hours\": [\n      {\n        \"label\": \"Thanksgiving\",\n        \"date\": \"2022-09-29\",\n        \"open\": true,\n        \"opening\": \"09:00\",\n        \"closing\": \"09:00\",\n        \"all_day\": false,\n        \"annual\": false\n      }\n    ]\n  }\n]")
  .asString();
```

```php
<?php
require_once('vendor/autoload.php');

$client = new \GuzzleHttp\Client();

$response = $client->request('POST', 'https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations', [
  'body' => '[
  {
    "code": "BIGC-1",
    "label": "Central store",
    "description": "Central shop of the world",
    "managed_by_external_source": false,
    "type_id": "PHYSICAL",
    "enabled": true,
    "operating_hours": {
      "sunday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      },
      "monday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      },
      "tuesday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      },
      "wednesday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      },
      "thursday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      },
      "friday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      },
      "saturday": {
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      }
    },
    "time_zone": "Etc/UTC",
    "address": {
      "address1": "5th Ave",
      "city": "New York",
      "state": "NY",
      "zip": "10021",
      "email": "test@example.com",
      "geo_coordinates": {
        "latitude": 40.774378,
        "longitude": -73.9653178
      },
      "country_code": "US",
      "address2": "string",
      "phone": "800-555-0198"
    },
    "storefront_visibility": true,
    "special_hours": [
      {
        "label": "Thanksgiving",
        "date": "2022-09-29",
        "open": true,
        "opening": "09:00",
        "closing": "09:00",
        "all_day": false,
        "annual": false
      }
    ]
  }
]',
  'headers' => [
    'Content-Type' => 'application/json',
    'X-Auth-Token' => '<apiKey>',
  ],
]);

echo $response->getBody();
```

```csharp
using RestSharp;

var client = new RestClient("https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations");
var request = new RestRequest(Method.POST);
request.AddHeader("X-Auth-Token", "<apiKey>");
request.AddHeader("Content-Type", "application/json");
request.AddParameter("application/json", "[\n  {\n    \"code\": \"BIGC-1\",\n    \"label\": \"Central store\",\n    \"description\": \"Central shop of the world\",\n    \"managed_by_external_source\": false,\n    \"type_id\": \"PHYSICAL\",\n    \"enabled\": true,\n    \"operating_hours\": {\n      \"sunday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"monday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"tuesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"wednesday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"thursday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"friday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      },\n      \"saturday\": {\n        \"open\": false,\n        \"opening\": \"08:00\",\n        \"closing\": \"16:00\"\n      }\n    },\n    \"time_zone\": \"Etc/UTC\",\n    \"address\": {\n      \"address1\": \"5th Ave\",\n      \"city\": \"New York\",\n      \"state\": \"NY\",\n      \"zip\": \"10021\",\n      \"email\": \"test@example.com\",\n      \"geo_coordinates\": {\n        \"latitude\": 40.774378,\n        \"longitude\": -73.9653178\n      },\n      \"country_code\": \"US\",\n      \"address2\": \"string\",\n      \"phone\": \"800-555-0198\"\n    },\n    \"storefront_visibility\": true,\n    \"special_hours\": [\n      {\n        \"label\": \"Thanksgiving\",\n        \"date\": \"2022-09-29\",\n        \"open\": true,\n        \"opening\": \"09:00\",\n        \"closing\": \"09:00\",\n        \"all_day\": false,\n        \"annual\": false\n      }\n    ]\n  }\n]", ParameterType.RequestBody);
IRestResponse response = client.Execute(request);
```

```swift
import Foundation

let headers = [
  "X-Auth-Token": "<apiKey>",
  "Content-Type": "application/json"
]
let parameters = [
  [
    "code": "BIGC-1",
    "label": "Central store",
    "description": "Central shop of the world",
    "managed_by_external_source": false,
    "type_id": "PHYSICAL",
    "enabled": true,
    "operating_hours": [
      "sunday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ],
      "monday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ],
      "tuesday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ],
      "wednesday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ],
      "thursday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ],
      "friday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ],
      "saturday": [
        "open": false,
        "opening": "08:00",
        "closing": "16:00"
      ]
    ],
    "time_zone": "Etc/UTC",
    "address": [
      "address1": "5th Ave",
      "city": "New York",
      "state": "NY",
      "zip": "10021",
      "email": "test@example.com",
      "geo_coordinates": [
        "latitude": 40.774378,
        "longitude": -73.9653178
      ],
      "country_code": "US",
      "address2": "string",
      "phone": "800-555-0198"
    ],
    "storefront_visibility": true,
    "special_hours": [
      [
        "label": "Thanksgiving",
        "date": "2022-09-29",
        "open": true,
        "opening": "09:00",
        "closing": "09:00",
        "all_day": false,
        "annual": false
      ]
    ]
  ]
] as [String : Any]

let postData = JSONSerialization.data(withJSONObject: parameters, options: [])

let request = NSMutableURLRequest(url: NSURL(string: "https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations")! as URL,
                                        cachePolicy: .useProtocolCachePolicy,
                                    timeoutInterval: 10.0)
request.httpMethod = "POST"
request.allHTTPHeaderFields = headers
request.httpBody = postData as Data

let session = URLSession.shared
let dataTask = session.dataTask(with: request as URLRequest, completionHandler: { (data, response, error) -> Void in
  if (error != nil) {
    print(error as Any)
  } else {
    let httpResponse = response as? HTTPURLResponse
    print(httpResponse)
  }
})

dataTask.resume()
```