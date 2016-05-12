<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Nelmio\ApiDocBundle\Annotation\ApiDoc;

/**
 * @Route("/api/v1/")
 */
class ApiController extends BaseController
{
    /**
     * Lists data about ALL parties
     * 
     * @Route("parties/", name="ppi_api_parties")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=false,
     *  section="Party",
     *  filters={
     *      {"name"="show_defunct", "description"="List defunct parties.",
     *          "dataType"="bool", "pattern"="0|1|null"},
     *      {"name"="int_membership", "description"="List only members of an international organization.",
     *          "dataType"="string", "pattern"="ppi|ppeu|null"},
     *      {"name"="sort_results", "description"="List results in a set order.",
     *          "dataType"="string", "pattern"="name|code|country|null"}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         400="Returned upon bad request.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partiesAction()
    {

/*      $countryFilter = $_GET['country'];      // currently obsolete, no countries with multiple parties
        $regionFilter = $_GET['region'];        // currently obsolete, always null
        $typeFilter = $_GET['party_type'];      // currently obsolete, always national
        $parentFilter = $_GET['parent_party'];  // currently obsolete, always null
*/      
        $showDefunct = (!isset($_GET['show_defunct'])) ? null : $_GET['show_defunct'];
        switch($showDefunct) {
            case null:
            case true:
            case false:
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: invalid parameter for the field 'show_defunct' (boolean expected)."), 400);
        }

        $membershipFilter = (!isset($_GET['int_membership'])) ? null : $_GET['int_membership'];
        switch ($membershipFilter) {
            case null:
            case 'ppi':
            case 'ppeu':
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$membershipFilter."' is not a valid parameter for the field 'int_membership'."), 400);
        }

        $orderBy = (!isset($_GET['sort_results'])) ? null : $_GET['sort_results'];
        switch ($orderBy) {
            case null:
            case 'name':
            case 'code':
            case 'country':
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$orderBy."' is not a valid parameter for the field 'sort_results'."), 400);
        }

        // run through BaseController
        $allData = $this->getAllParties($showDefunct, $membershipFilter, $orderBy); // , $countryFilter, $regionFilter, $typeFilter, $parentFilter);

        $serializer = $this->get('jms_serializer');
        $allData = $serializer->serialize($allData, 'json');

        if ($allData === null) {
            return new JsonResponse(array("error"=>"Search returned no results."), 404);
        }

	    return new Response($allData, 200);
    }

    /**
     * List data about ONE party
     * 
     * @Route("parties/{id}", name="ppi_api_parties_id")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=true,
     *  section="Party",
     *  requirements={
     *      {"name"="id", "dataType"="string", "required"=true, "description"="Party code (i.e. 'ppsi')."}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partyAction($id) {
    	
        $data = $this->getOneParty($id);

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($data, 'json');

    	if ($data === null) {
    		return new JsonResponse(array("error"=>"Party with this ID does not exsist"), 404);
    	}

    	return new Response($data, 200);
    }



}
