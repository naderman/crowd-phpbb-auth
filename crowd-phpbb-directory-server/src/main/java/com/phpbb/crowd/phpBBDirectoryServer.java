/**
 * Copyright 2010 Nils Adermann
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
package com.phpbb.crowd;

import com.atlassian.crowd.integration.SearchContext;
import com.atlassian.crowd.integration.authentication.PasswordCredential;
import com.atlassian.crowd.integration.directory.RemoteDirectory;
import com.atlassian.crowd.integration.exception.*;
import com.atlassian.crowd.integration.model.*;
import com.atlassian.crowd.integration.model.user.*;
import com.atlassian.crowd.integration.model.group.*;
import com.atlassian.crowd.integration.model.membership.*;
import com.atlassian.crowd.search.query.membership.*;
import com.atlassian.crowd.search.query.entity.*;
import com.atlassian.crowd.search.query.entity.restriction.*;
import com.atlassian.crowd.search.ReturnType;

import java.rmi.RemoteException;
import java.util.*;

import org.apache.log4j.Logger;

import java.io.*;
import java.net.*;

/**
 * The phpBB Directory Server provides an interface to phpBB users and groups.
 *
 * All access is read-only. The data is retrieved through HTTP requests to a
 * special file that has to be dropped into the phpBB root directory.
 */
public class phpBBDirectoryServer implements RemoteDirectory
{
    private static final Logger log = Logger.getLogger(phpBBDirectoryServer.class);

    private long directoryId;
    private Map<String,String> attributes;

    private String serverUrl;

    /**
    * Constructor of the phpBB Directory Server reads the phpBB board root URL from
    * the environment variable CROWD_PHPBB_ROOT_URL.
    *
    * Make sure to export this variable before starting crowd.
    */
    public phpBBDirectoryServer()
    {
        serverUrl = System.getenv("CROWD_PHPBB_ROOT_URL");
    }

    public long getDirectoryId()
    {
        return directoryId;
    }

    public void setDirectoryId(long directoryId)
    {
        this.directoryId = directoryId;
    }

    public String getName()
    {
        return "phpbbauth";
    }

    public String getDescriptiveName()
    {
        return "phpBB Directory Server";
    }

    public void setAttributes(Map<String,String> attributes)
    {
        this.attributes = attributes;
    }

    public List<String> getAttributes(String name)
    {
        log.info("crowd-phpbbauth-plugin: getAttributes: " + name);
        return new ArrayList<String>();
    }

    public String getAttribute(String name)
    {
        log.info("crowd-phpbbauth-plugin: getAttribute: " + name);
        return "";
    }

    public Set<String> getAttributeNames()
    {
        log.info("crowd-phpbbauth-plugin: getAttributeNames");
        return new HashSet<String>();
    }

    public boolean hasAttribute(String name)
    {
        log.info("crowd-phpbbauth-plugin: hasAttribute: " + name);
        return false;
    }

    public User findUserByName(String name)
        throws ObjectNotFoundException
    {
        log.info("crowd-phpbbauth-plugin: findUserByName: " + name);

        SearchRestriction searchRestriction = new TermRestriction(new Property("name", String.class), MatchMode.EXACTLY_MATCHES, name);
        UserQuery query = new UserQuery(searchRestriction, 0, 1); // start, max

        List list = new ArrayList<UserTemplate>();
        searchEntities("searchUsers", new UserEntityCreator(getDirectoryId()), query, list);

        if (list.size() == 0)
        {
            throw new ObjectNotFoundException();
        }

        return (User) list.get(0);

    }

    public UserWithAttributes findUserWithAttributesByName(String name)
        throws ObjectNotFoundException
    {
        log.info("crowd-phpbbauth-plugin: findUserWithAttributesByName: " + name);

        // no attributes support, so just fake it
        User user = findUserByName(name);

        return (UserWithAttributes) new UserEntityCreator(getDirectoryId()).attachAttributes(user);
    }

    /**
     * Authenticates a user against the phpBB authentication method.
     *
     * Username and password are sent in a urlencoded POST request.
     * The request returns either an error line and an error message
     * or success and the data required to create a UserTemplate
     * object. phpBB still counts login attempts, so brute force is not
     * possible regardless of how this method is used.
     *
     * @param    name        The username.
     * @param    credential  Credential object containing the password.
     *
     * @return               A UserTemplate object, which
     *                       implements the User interface.
     *
     * @throws   InactiveAccountException        Account inactive or login
     *                                           attempts exceeded.
     * @throws   InvalidAuthenticationException  Invalid username or password.
     * @throws   ObjectNotFoundException         Any other errors
     */
    public User authenticate(String name, PasswordCredential credential)
        throws ObjectNotFoundException, InactiveAccountException, InvalidAuthenticationException
    {
        HashMap<String, String> params = new HashMap<String, String>();
        params.put("action", "authenticate");
        params.put("name", name);
        params.put("credential", credential.getCredential());

        ArrayList<String> result = sendPostRequest(params);

        if (result.size() < 2)
        {
            throw new ObjectNotFoundException();
        }

        log.info("crowd-phpbbauth-plugin: authenticate: " + name);
        log.info("crowd-phpbbauth-plugin: result: " + result.get(0));
        log.info("crowd-phpbbauth-plugin: result: " + result.get(1));

        if (!result.get(0).equals("success"))
        {
            String error = result.get(1);

            if (error.equals("LOGIN_ERROR_ATTEMPTS") || error.equals("ACTIVE_ERROR"))
            {
                throw new InactiveAccountException();
            }

            throw new InvalidAuthenticationException();
        }

        UserEntityCreator entityCreator = new UserEntityCreator(getDirectoryId());
        return (User) entityCreator.fromLine(result.get(1));
    }

    public User addUser(UserTemplate user, PasswordCredential credential)
        throws InvalidUserException, ObjectNotFoundException, InvalidCredentialException
    {
        throw new ObjectNotFoundException();
    }

    public User updateUser(UserTemplate user)
        throws InvalidUserException, ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void updateUserCredential(String username, PasswordCredential credential)
        throws ObjectNotFoundException, InvalidCredentialException
    {
        throw new ObjectNotFoundException();
    }

    public User renameUser(String oldName, String newName)
        throws ObjectNotFoundException, InvalidUserException
    {
        throw new ObjectNotFoundException();
    }

    public void storeUserAttributes(String username, Map<String,List<String>> attributes)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void removeUserAttributes(String username, String attributeName)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void removeUser(String name)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public List searchUsers(EntityQuery query)
    {
        log.info("crowd-phpbbauth-plugin: searchUsers");

        List list = new ArrayList<UserTemplate>();
        searchEntities("searchUsers", new UserEntityCreator(getDirectoryId()), query, list);

        return list;
    }

    public Group findGroupByName(String name)
        throws ObjectNotFoundException
    {
        log.info("crowd-phpbbauth-plugin: findGroupByName: " + name);

        SearchRestriction searchRestriction = new TermRestriction(new Property("name", String.class), MatchMode.EXACTLY_MATCHES, name);
        GroupQuery query = new GroupQuery(searchRestriction, 0, 1); // start, max

        List list = new ArrayList<GroupTemplate>();
        searchEntities("searchGroups", new GroupEntityCreator(getDirectoryId()), query, list);

        if (list.size() == 0)
        {
            throw new ObjectNotFoundException();
        }

        return (Group) list.get(0);
    }

    public GroupWithAttributes findGroupWithAttributesByName(String name)
        throws ObjectNotFoundException
    {
        log.info("crowd-phpbbauth-plugin: findGroupWithAttributesByName: " + name);

        // no attributes support, so just fake it
        Group group = findGroupByName(name);

        return (GroupWithAttributes) new GroupEntityCreator(getDirectoryId()).attachAttributes(group);
    }

    public Group addGroup(GroupTemplate group)
        throws InvalidGroupException, ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public Group updateGroup(GroupTemplate group)
        throws InvalidGroupException, ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public Group renameGroup(String oldName, String newName)
        throws ObjectNotFoundException, InvalidGroupException
    {
        throw new ObjectNotFoundException();
    }

    public void storeGroupAttributes(String groupName, Map<String,List<String>> attributes)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void removeGroupAttributes(String groupName, String attributeName)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void removeGroup(String name)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public List searchGroups(EntityQuery query)
    {
        log.info("crowd-phpbbauth-plugin: searchGroups");

        List list = new ArrayList<GroupTemplate>();
        searchEntities("searchGroups", new GroupEntityCreator(getDirectoryId()), query, list);

        return list;
    }

    public boolean isUserDirectGroupMember(String username, String groupName)
    {
        log.info("crowd-phpbbauth-plugin: isUserDirectGroupMember: " + username + ", " + groupName);
        EntityCreator creator;
        List list;
        EntityQuery entityQuery;

        SearchRestriction searchRestrictionUser = new TermRestriction(
            new Property("name", String.class),
            MatchMode.EXACTLY_MATCHES,
            username
        );

        SearchRestriction searchRestrictionGroup = new TermRestriction(
            new Property("groupname", String.class),
            MatchMode.EXACTLY_MATCHES,
            groupName
        );

        SearchRestriction searchRestriction = new MultiTermRestriction(
            MultiTermRestriction.BooleanLogic.AND,
            searchRestrictionUser,
            searchRestrictionGroup
        );

        list = new ArrayList<String>();
        entityQuery = new GroupQuery(searchRestriction, 0, 1); // start, max
        entityQuery = new EntityQuery(entityQuery, ReturnType.NAME);

        searchEntities("userMemberships", null, entityQuery, list);

        if (list.size() > 0)
        {
            return true;
        }
        return false;
    }

    /**
     * No nested group support, so a group can never be a direct member.
     *
     * @return   Always false.
     */
    public boolean isGroupDirectGroupMember(String childGroup, String parentGroup)
    {
        log.info("crowd-phpbbauth-plugin: isGroupDirectGroupMember");
        return false;
    }

    public void addUserToGroup(String username, String groupName)
        throws ObjectNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void addGroupToGroup(String childGroup, String parentGroup)
        throws ObjectNotFoundException, InvalidMembershipException
    {
        throw new ObjectNotFoundException();
    }

    public void removeUserFromGroup(String username, String groupName)
        throws ObjectNotFoundException, MembershipNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public void removeGroupFromGroup(String childGroup, String parentGroup)
        throws ObjectNotFoundException, InvalidMembershipException, MembershipNotFoundException
    {
        throw new ObjectNotFoundException();
    }

    public List searchGroupRelationships(MembershipQuery query)
    {
        log.info("crowd-phpbbauth-plugin: searchGroupRelationships");

        EntityCreator creator;
        List list;
        EntityQuery entityQuery;
        String action;

        SearchRestriction searchRestriction = new TermRestriction(
            new Property("name", String.class),
            MatchMode.EXACTLY_MATCHES,
            query.getEntityNameToMatch()
        );

        //if (query.getEntityToMatch().getEntityType() == Entity.GROUP)
        if (query.isFindMembers())
        {
            action = "groupMembers";
            creator = new UserEntityCreator(getDirectoryId());
            list = new ArrayList<UserTemplate>();
            entityQuery = new UserQuery(searchRestriction, query.getStartIndex(), query.getMaxResults());
        }
        else // assume Entity.USER
        {
            action = "userMemberships";
            creator = new GroupEntityCreator(getDirectoryId());
            list = new ArrayList<GroupTemplate>();
            entityQuery = new GroupQuery(searchRestriction, query.getStartIndex(), query.getMaxResults());
        }

        if (query.getReturnType() != ReturnType.ENTITY)
        {
            list = new ArrayList<String>();
            entityQuery = new EntityQuery(entityQuery, query.getReturnType());
        }

        searchEntities(action, creator, entityQuery, list);

        return list;
    }

    public void testConnection()
        throws DirectoryAccessException
    {
        // could implement a simple http request here
    }

    /**
     * phpBB does not support nested Groups
     *
     * @return   Always false.
     */
    public boolean supportsNestedGroups()
    {
        return false;
    }

    protected void searchEntities(String action, EntityCreator entityCreator, EntityQuery query, List list)
    {
        HashMap<String, String> params = new HashMap<String, String>();
        params.put("action", action);
        params.put("start", new Integer(query.getStartIndex()).toString());
        params.put("max", new Integer(query.getMaxResults()).toString());
        params.put("returnType", query.getReturnType().toString()); // NAME or ENTITY

        SearchRestriction restriction = query.getSearchRestriction();
        if (restriction != null)
        {
            params.put("restriction", restrictionToJson(restriction));
        }

        ArrayList<String> result = sendPostRequest(params);
        if (result.size() > 0)
        {
            log.info("crowd-phpbbauth-plugin: result: " + result.get(0));
        }

        for (Iterator it = result.iterator(); it.hasNext(); )
        {
            String line = (String) it.next();

            if (query.getReturnType() == ReturnType.ENTITY || query instanceof UserQuery)
            {
                try
                {
                    list.add(entityCreator.fromLine(line));
                }
                catch (ObjectNotFoundException e)
                {
                }
            }
            else
            {
                list.add(line);
            }
        }
    }

    protected String restrictionToJson(SearchRestriction restriction)
    {
        if (restriction instanceof NullRestriction)
        {
            return "null";
        }
        else if (restriction instanceof TermRestriction)
        {
            TermRestriction termRestriction = (TermRestriction) restriction;

            return "{\"mode\": \"" + termRestriction.getMatchMode().toString() + "\", \"property\": \"" + escape(termRestriction.getPropertyName()) + "\", \"value\": \"" + escape(termRestriction.getValue().toString()) + "\"}";
        }
        else if (restriction instanceof MultiTermRestriction)
        {
            MultiTermRestriction multiTermRestriction = (MultiTermRestriction) restriction;
            Collection<SearchRestriction> restrictions = multiTermRestriction.getRestrictions();

            String result = "{\"boolean\": \"" + multiTermRestriction.getBooleanLogic().toString() + "\", \"terms\": [";

            for (Iterator it = restrictions.iterator(); it.hasNext(); )
            {
                result += restrictionToJson((SearchRestriction) it.next());

                if (it.hasNext())
                {
                    result += ", ";
                }
            }
            result += "]}";

            return result;
        }
        else if (restriction instanceof MutableMultiTermRestriction)
        {
            return restrictionToJson(((MutableMultiTermRestriction) restriction).getSearchRestriction());
        }

        return "null";
    }

    /**
     * Escapes a string for use in a JSON string.
     *
     * @param    source  The unescaped input string.
     *
     * @return           Source string with backslashes and quotes escaped.
     */
    protected String escape(String source)
    {
        String result;

        // double backslashes because of java string, and those doubled again
        // because it's a regex. so this is really just
        // (1) \ => \\
        // (2) " => \"
        result = source.replaceAll("\\\\", "\\\\\\\\");
        result = result.replaceAll("\"", "\\\\\\\"");

        return result;
    }

    protected ArrayList<String> sendPostRequest(Map<String, String> params)
    {
        String data = "";
        ArrayList<String> result = new ArrayList<String>();

        try
        {
            for (Map.Entry<String, String> entry : params.entrySet())
            {
                data += URLEncoder.encode(entry.getKey(), "UTF-8");
                data += "=";
                data += URLEncoder.encode(entry.getValue(), "UTF-8");
                data += "&";
            }

            URL url = new URL(serverUrl + "auth_api.php");

            URLConnection connection = url.openConnection();
            connection.setDoOutput(true);

            OutputStreamWriter writer = new OutputStreamWriter(connection.getOutputStream());

            writer.write(data);
            writer.flush();

            BufferedReader reader = new BufferedReader(new InputStreamReader(connection.getInputStream()));

            String line;
            while ((line = reader.readLine()) != null)
            {
                result.add(line);
            }

            writer.close();
            reader.close();
        }
        catch (Exception e)
        {
            log.error("crowd-phpbbauth-plugin: HTTP request: " + e);
        }

        return result;
    }
}