/**
 * Copyright 2010 phpBB Ltd.
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

import com.atlassian.crowd.exception.*;

public abstract class EntityCreator
{
    private long directoryId;

    public EntityCreator(long directoryId)
    {
        setDirectoryId(directoryId);
    }

    public long getDirectoryId()
    {
        return directoryId;
    }

    public void setDirectoryId(long directoryId)
    {
        this.directoryId = directoryId;
    }

    public Object fromLine(String line)
        throws ObjectNotFoundException
    {
        String[] values = line.split("\t");

        if (values.length < this.minProperties())
        {
            throw new ObjectNotFoundException();
        }

        return this.hydrateObject(values);
    }

    abstract public int minProperties();
    abstract public Object hydrateObject(String[] values);
}