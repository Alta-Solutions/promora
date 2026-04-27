> For clean Markdown of any page, append .md to the page URL.
> For a complete documentation index, see https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/inventory/locations/llms.txt.
> For full documentation content, see https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/inventory/locations/llms-full.txt.

# List Locations

GET https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations

List locations. You can use optional filter parameters.

**Limits**
* Limit of 50 concurrent requests.
* Limit of 1000 items for payload length.


Reference: https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/inventory/locations/get-locations

## OpenAPI Specification

```yaml
openapi: 3.1.0
info:
  title: management-inventory
  version: 1.0.0
paths:
  /inventory/locations:
    get:
      operationId: get-locations
      summary: List Locations
      description: |
        List locations. You can use optional filter parameters.

        **Limits**
        * Limit of 50 concurrent requests.
        * Limit of 1000 items for payload length.
      tags:
        - subpackage_locations
      parameters:
        - name: location_id:in
          in: query
          description: Comma separated list of `location_id`.
          required: false
          schema:
            type: integer
        - name: location_code:in
          in: query
          description: Comma separated list of `location_code`.
          required: false
          schema:
            type: string
        - name: is_default
          in: query
          description: Filter whether the location is the default.
          required: false
          schema:
            type: boolean
        - name: type_id:in
          in: query
          description: Comma separated list of locations type codes.
          required: false
          schema:
            type: string
        - name: managed_by_external_source
          in: query
          description: Filter whether an external source manages location inventory levels.
          required: false
          schema:
            type: boolean
        - name: is_active
          in: query
          description: Filter by active locations flag; return both if not specified.
          required: false
          schema:
            type: boolean
        - name: storefront_visibility
          in: query
          description: Filter by storefront_visibility flag; return both if not specified.
          required: false
          schema:
            type: boolean
        - name: page
          in: query
          description: |
            Specifies the page number in a limited (paginated) list of products.
          required: false
          schema:
            type: integer
        - name: limit
          in: query
          description: >
            Controls the number of items per page in a limited (paginated) list
            of products.
          required: false
          schema:
            type: integer
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
          description: The request has been successfully processed.
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Locations_getLocations_Response_200'
servers:
  - url: https://api.bigcommerce.com/stores/store_hash/v3
components:
  schemas:
    LocationResponseTypeId:
      type: string
      enum:
        - PHYSICAL
        - VIRTUAL
      default: PHYSICAL
      description: Describe type of given location.
      title: LocationResponseTypeId
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
    LocationResponseAddressGeoCoordinates:
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
      description: Object with latitude and longitude that points at the location.
      title: LocationResponseAddressGeoCoordinates
    LocationResponseAddress:
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
          $ref: '#/components/schemas/LocationResponseAddressGeoCoordinates'
          description: Object with latitude and longitude that points at the location.
        country_code:
          type: string
          description: ISO 3166-1 alpha-3 code.
      title: LocationResponseAddress
    LocationResponseSpecialHoursItems:
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
      title: LocationResponseSpecialHoursItems
    LocationResponse:
      type: object
      properties:
        id:
          type: integer
          description: Location immutable unique identifier.
        code:
          type: string
          description: Location user-defined unique identifier.
        label:
          type: string
          description: Location label.
        description:
          type: string
          description: Description of location.
        managed_by_external_source:
          type: boolean
          default: false
          description: >
            Indicates if the third-party system is the source of truth for
            inventory values. If set to true, manually editing inventory in the
            BigCommerce control panel will be disabled.
        type_id:
          $ref: '#/components/schemas/LocationResponseTypeId'
          description: Describe type of given location.
        enabled:
          type: boolean
          default: true
          description: Indicator of accessibility of the location.
        operating_hours:
          $ref: '#/components/schemas/OperatingHours'
        time_zone:
          type: string
          description: Time zone of location.
        created_at:
          type: string
          format: date-time
          description: Time when location was created.
        updated_at:
          type: string
          format: date-time
          description: Time of last update of the location.
        address:
          $ref: '#/components/schemas/LocationResponseAddress'
        storefront_visibility:
          type: boolean
          default: true
          description: Indicator of accessibility of location on the storefront.
        special_hours:
          type: array
          items:
            $ref: '#/components/schemas/LocationResponseSpecialHoursItems'
      title: LocationResponse
    MetaPaginationLinks:
      type: object
      properties:
        previous:
          type: string
          description: The link to the previous page is returned in the response.
        current:
          type: string
          description: A link to the current page is returned in the response.
        next:
          type: string
          description: Link to the next page returned in the response.
      description: >-
        Pagination links for the previous and next parts of the whole
        collection.
      title: MetaPaginationLinks
    MetaPagination:
      type: object
      properties:
        total:
          type: integer
          description: The total number of items in the result set.
        count:
          type: integer
          description: The total number of items in the collection on current page.
        per_page:
          type: integer
          description: >-
            The number of items returned in the collection per page, controlled
            by the limit parameter.
        current_page:
          type: integer
          description: The page you are currently on within the collection.
        total_pages:
          type: integer
          description: The total number of pages in the collection.
        links:
          $ref: '#/components/schemas/MetaPaginationLinks'
          description: >-
            Pagination links for the previous and next parts of the whole
            collection.
      title: MetaPagination
    Meta:
      type: object
      properties:
        pagination:
          $ref: '#/components/schemas/MetaPagination'
      title: Meta
    Locations_getLocations_Response_200:
      type: object
      properties:
        data:
          type: array
          items:
            $ref: '#/components/schemas/LocationResponse'
        meta:
          $ref: '#/components/schemas/Meta'
      title: Locations_getLocations_Response_200
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

headers = {"X-Auth-Token": "<apiKey>"}

response = requests.get(url, headers=headers)

print(response.json())
```

```javascript
const url = 'https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations';
const options = {method: 'GET', headers: {'X-Auth-Token': '<apiKey>'}};

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
	"net/http"
	"io"
)

func main() {

	url := "https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations"

	req, _ := http.NewRequest("GET", url, nil)

	req.Header.Add("X-Auth-Token", "<apiKey>")

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

request = Net::HTTP::Get.new(url)
request["X-Auth-Token"] = '<apiKey>'

response = http.request(request)
puts response.read_body
```

```java
import com.mashape.unirest.http.HttpResponse;
import com.mashape.unirest.http.Unirest;

HttpResponse<String> response = Unirest.get("https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations")
  .header("X-Auth-Token", "<apiKey>")
  .asString();
```

```php
<?php
require_once('vendor/autoload.php');

$client = new \GuzzleHttp\Client();

$response = $client->request('GET', 'https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations', [
  'headers' => [
    'X-Auth-Token' => '<apiKey>',
  ],
]);

echo $response->getBody();
```

```csharp
using RestSharp;

var client = new RestClient("https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations");
var request = new RestRequest(Method.GET);
request.AddHeader("X-Auth-Token", "<apiKey>");
IRestResponse response = client.Execute(request);
```

```swift
import Foundation

let headers = ["X-Auth-Token": "<apiKey>"]

let request = NSMutableURLRequest(url: NSURL(string: "https://api.bigcommerce.com/stores/store_hash/v3/inventory/locations")! as URL,
                                        cachePolicy: .useProtocolCachePolicy,
                                    timeoutInterval: 10.0)
request.httpMethod = "GET"
request.allHTTPHeaderFields = headers

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