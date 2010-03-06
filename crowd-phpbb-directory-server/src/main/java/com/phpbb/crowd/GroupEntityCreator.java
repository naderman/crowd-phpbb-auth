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
import com.atlassian.crowd.integration.model.group.*;
import com.atlassian.crowd.integration.model.AttributeAware;

import com.phpbb.crowd.EntityCreator;

public class GroupEntityCreator extends EntityCreator
{
    public GroupEntityCreator(long directoryId)
    {
        super(directoryId);
    }

    public int minProperties()
    {
        return 2;
    }

    public Object hydrateObject(String[] values)
    {
        GroupTemplate group = new GroupTemplate(values[1], getDirectoryId(), GroupType.GROUP);

        group.setActive(true);
        // description might not be set
        if (values.length > 3)
        {
            group.setDescription(values[3]);
        }

        return group;
    }

    /**
    * Makes a GroupTemplateWithAttributes from a regular Group object.
    *
    * phpBB does not actually support attributes so we use this to create
    * wrapper objects for the places that require it.
    *
    * @param    base    The reguler Group object containing all group data.
    *
    * @return           An implementation of UserWithAttributes, that contains
    *                   all the basic data passed in the parameter.
    */
    public AttributeAware attachAttributes(Object baseObject)
    {
        Group base = (Group) baseObject;
        GroupTemplateWithAttributes group = new GroupTemplateWithAttributes(base.getName(), getDirectoryId(), GroupType.GROUP);

        group.setDescription(base.getDescription());
        group.setName(base.getName());
        group.setActive(base.isActive());

        return group;
    }
}