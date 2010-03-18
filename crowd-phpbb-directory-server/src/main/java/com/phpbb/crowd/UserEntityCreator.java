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

import com.atlassian.crowd.integration.exception.*;
import com.atlassian.crowd.integration.model.user.*;
import com.atlassian.crowd.integration.model.AttributeAware;

import com.phpbb.crowd.EntityCreator;

public class UserEntityCreator extends EntityCreator
{
    public UserEntityCreator(long directoryId)
    {
        super(directoryId);
    }

    public int minProperties()
    {
        return 4;
    }

	// properties: user_id, username, email, user_type, avatar, firstname, lastname
    public Object hydrateObject(String[] values)
    {
        UserTemplate user = new UserTemplate(values[1], getDirectoryId());

		if (values.length > 6 && values[5] != null && values[6] != null)
		{
			user.setFirstName(values[5]);
	        user.setLastName(values[6]);
			user.setDisplayName(values[5] + " " + values[6]);
		}
		else
		{
			user.setFirstName("");
	        user.setLastName(values[1]);
			user.setDisplayName(values[1]);
		}

        user.setName(values[1]);
        user.setEmailAddress(values[2]);

        if (values[3].equals("1") || values[3].equals("2"))
        {
            user.setActive(false);
        }
        else
        {
            user.setActive(true);
        }

        // avatar might not be set
        if (values.length > 4 && values[4] != null)
        {
            user.setIconLocation(values[4]);
        }

        return user;
    }

    /**
    * Makes a UserTemplateWithAttributes from a regular User object.
    *
    * phpBB does not actually support attributes so we use this to create
    * wrapper objects for the places that require it.
    *
    * @param    base    The reguler User object containing all user data.
    *
    * @return           An implementation of UserWithAttributes, that contains
    *                   all the basic data passed in the parameter.
    */
    public AttributeAware attachAttributes(Object baseObject)
    {
        User base = (User) baseObject;

        UserTemplateWithAttributes user = new UserTemplateWithAttributes(base.getName(), getDirectoryId());

        user.setFirstName(base.getFirstName());
        user.setLastName(base.getLastName());
        user.setDisplayName(base.getDisplayName());
        user.setName(base.getName());
        user.setEmailAddress(base.getEmailAddress());
        user.setActive(base.isActive());
        user.setIconLocation(base.getIconLocation());

        return user;
    }
}